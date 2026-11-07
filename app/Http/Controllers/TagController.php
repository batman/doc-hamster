<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Folder;
use App\Domain\Documents\Tag;
use App\Domain\Org\CurrentOrg;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The tag list of an organisation (#32), for Admins and the checkbox "Tags
 * bearbeiten". Deleting a tag keeps the receipts; if it is in use, the count
 * is named first and has to be confirmed.
 */
final class TagController
{
    /** Any colour as #rrggbb (Q-29); lower case keeps the stylesheet safe. */
    private const COLOR = ['nullable', 'string', 'regex:/^#[0-9a-f]{6}$/'];

    /** The "#" is drawn in front of every pill, so a typed one is dropped. An own colour beats the palette. */
    private function cleanName(Request $request): void
    {
        $color = $request->filled('color_hex') ? $request->input('color_hex') : $request->input('color');

        $request->merge([
            'name' => ltrim(trim((string) $request->input('name')), '#'),
            'color' => is_string($color) && $color !== '' ? mb_strtolower(trim($color)) : null,
        ]);
    }

    /** Pills left, editor right (design point 19); `?tag=` opens one in the editor. */
    public function index(Request $request): View
    {
        $tags = Tag::query()->withCount('documents')->orderBy('name')->get();
        $wanted = $request->query('tag');

        return view('tags.index', ['tags' => $tags, 'tag' => is_string($wanted) ? $tags->firstWhere('id', $wanted) : null]);
    }

    /** One rule per tag and per coloured folder: the CSP allows no inline styles. */
    public function css(Request $request): Response
    {
        $css = Tag::query()->get(['id', 'color'])
            ->map(static fn (Tag $tag): string => sprintf('.tag-%s{--tag:%s;--tag-text:%s}', $tag->id, $tag->color, $tag->textColor()))
            ->merge(Folder::query()->whereNotNull('color')->get(['id', 'color'])
                ->filter(static fn (Folder $folder): bool => preg_match('/^#[0-9a-f]{6}$/', (string) $folder->color) === 1)
                ->map(static fn (Folder $folder): string => sprintf('.fc-%s{--folder:%s}', $folder->id, $folder->color)))
            ->implode('
');

        $response = response($css, 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'private, no-cache']);
        $response->setEtag(md5($css))->isNotModified($request);

        return $response;
    }

    public function store(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $this->cleanName($request);
        $data = $request->validate(['name' => $this->nameRule(), 'color' => self::COLOR]);

        // A new tag gets a colour of the palette by chance, unless one was picked (PO, 2026-11-01).
        $tag = Tag::query()->create(['name' => $data['name'], 'color' => $data['color'] ?? Tag::PALETTE[array_rand(Tag::PALETTE)]]);
        $this->log($audit, $request, $org, 'tag.created', $tag);

        return redirect()->route('tags.index', ['tag' => $tag->id])->with('status', __('tags.saved'));
    }

    public function update(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $tag = Tag::query()->whereKey($id)->firstOrFail();
        $this->cleanName($request);
        $data = $request->validate(['name' => $this->nameRule($tag), 'color' => self::COLOR]);

        $tag->update(['name' => $data['name'], 'color' => $data['color'] ?? $tag->color]);
        $this->log($audit, $request, $org, 'tag.renamed', $tag);

        return redirect()->route('tags.index', ['tag' => $tag->id])->with('status', __('tags.saved'));
    }

    public function destroy(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $tag = Tag::query()->withCount('documents')->whereKey($id)->firstOrFail();
        $count = (int) $tag->getAttribute('documents_count');

        if ($count > 0 && ! $request->boolean('confirmed')) {
            return redirect()->route('tags.index')->with('confirm_delete', ['id' => $tag->id, 'name' => $tag->name, 'count' => $count]);
        }

        $this->log($audit, $request, $org, 'tag.deleted', $tag, ['documents' => $count]);
        $tag->delete();

        return redirect()->route('tags.index')->with('status', __('tags.deleted'));
    }

    /**
     * @return list<mixed>
     */
    private function nameRule(?Tag $tag = null): array
    {
        return ['required', 'string', 'max:64', static function (string $attribute, mixed $value, Closure $fail) use ($tag): void {
            // In PHP, so the check doesn't depend on the database collation.
            $taken = Tag::query()->whereKeyNot($tag?->id)->pluck('name')
                ->map(static fn (string $name): string => mb_strtolower($name));

            if (is_string($value) && $taken->contains(mb_strtolower($value))) {
                $fail(__('tags.name_taken'));
            }
        }];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function log(AuditLog $audit, Request $request, CurrentOrg $org, string $action, Tag $tag, array $meta = []): void
    {
        $audit->record($action, orgId: $org->id(), userId: $org->membership()->user_id, subject: $tag, meta: ['name' => $tag->name, ...$meta], ip: $request->ip());
    }
}
