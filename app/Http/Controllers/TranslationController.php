<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Translate\DocumentTranslation;
use App\Domain\Translate\Translations;
use App\Infrastructure\Extensions\Catalogue;
use App\Infrastructure\Extensions\Extension;
use App\Infrastructure\Extensions\Extensions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Point 172: translation of the recognised text. The browser translates (modules/translate.js, a Web Worker with
 * Mozilla's bergamot-translator wasm) with the files of the installed packs served here, and stores the result –
 * one translation per target language, translating again replaces it. The server itself does not translate.
 */
final class TranslationController extends Controller
{
    /** The runtime and model files of an installed pack (catalogue names only; pinned, so cached long). */
    public function file(Extensions $extensions, string $key, string $name): BinaryFileResponse
    {
        $ext = Catalogue::find($key);
        abort_if($ext === null || $ext->group !== 'translate' || ! in_array($name, array_column($ext->files, 'name'), true) || ! $extensions->isInstalled($key), 404);

        return response()->file($extensions->path($ext->dir.'/'.$name), [
            'Content-Type' => str_ends_with($name, '.wasm') ? 'application/wasm' : 'application/octet-stream',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /** "Übersetzen" (172.2): stores the browser's translation; one per target language. */
    public function store(Request $request, CurrentOrg $org, AuditLog $audit, string $id): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', Rule::in(Translations::LANGUAGES)],
            'to' => ['required', 'string', Rule::in(Translations::TARGETS), 'different:from'],
            'text' => ['required', 'string', 'max:1000000'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $document = Document::query()->visibleTo($user->id, Gate::allows(Permission::ViewAllDocuments->value))->whereKey($id)->firstOrFail();

        DocumentTranslation::query()->updateOrCreate(
            ['document_id' => $document->id, 'to_lang' => $data['to']],
            ['org_id' => $document->org_id, 'from_lang' => $data['from'], 'text' => $data['text']],
        );
        $audit->record('document.changed', orgId: $org->id(), userId: $user->id, subject: $document, ip: $request->ip());

        return response()->json(['message' => __('translate.saved'), 'to' => $data['to'], 'label' => __('translate.heading', ['language' => __('translate.languages.'.$data['to'])])]);
    }

    /**
     * What the button "Übersetzen" needs (null = nothing to offer): the source language and per target the pairs
     * to run with their file URLs, plus the runtime.
     *
     * @return array{from: string, engine: string, glue: string, worker: string, store: string, targets: array<string, array{label: string, steps: list<array{from: string, to: string, files: array<string, string>}>}>}|null
     */
    public static function config(Document $document): ?array
    {
        $text = (string) $document->getAttribute('ocr_text');
        $engine = Catalogue::find('translate-engine');
        if (trim($text) === '' || $engine === null) {
            return null;
        }
        $from = Translations::source($document->getAttribute('ocr_langs'), $text);
        $targets = [];
        foreach (app(Translations::class)->targets($from) as $to => $pairs) {
            $targets[$to] = [
                'label' => __('translate.languages.'.$to),
                'steps' => array_map(static fn (Extension $pair): array => [
                    'from' => (string) $pair->meta['from'],
                    'to' => (string) $pair->meta['to'],
                    'files' => array_map(static fn (string $role): string => self::url($pair, (string) $pair->meta[$role]), ['model' => 'model', 'lex' => 'lex', 'vocab' => 'vocab']),
                ], $pairs),
            ];
        }
        if ($targets === []) {
            return null;
        }

        return [
            'from' => $from,
            'engine' => self::url($engine, $engine->files[0]['name']),
            'glue' => asset('vendor/bergamot/bergamot-translator.js').'?v='.$engine->version,
            'worker' => asset('js/translate-worker.js').'?v='.substr((string) @filemtime(public_path('js/translate-worker.js')), -6),
            'store' => route('documents.translations.store', $document->id),
            'targets' => $targets,
        ];
    }

    private static function url(Extension $ext, string $name): string
    {
        return route('translate.file', [$ext->key, $name]).'?v='.$ext->version;
    }
}
