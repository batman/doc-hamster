<?php

declare(strict_types=1);

namespace DocHamsterModules\Beispiel;

use App\Modules\Module as DocHamsterModule;
use App\Modules\ModuleContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Example module: a settings page with the number of documents, an entry in a document's ⋯ menu, and a note per
 * document in the module's own table (m_beispiel_notes). Everything goes through $context – see BUILDING.md.
 */
final class Module implements DocHamsterModule
{
    public function register(ModuleContext $context): void
    {
        $context->routes(static function () use ($context): void {
            Route::get('settings', static fn () => view('m-beispiel::settings', [
                'documents' => $context->documents()->count(),
                'notes' => DB::table('m_beispiel_notes')->count(),
            ]))->name('settings');
            Route::get('documents/{id}', static fn (string $id) => view('m-beispiel::document', [
                'document' => $context->documents()->findOrFail($id),
            ]))->name('document');
        });
        $context->settingsPage(__('m-beispiel::texts.title'), __('m-beispiel::texts.text'), 'puzzle', 'settings');
        $context->documentAction(__('m-beispiel::texts.action'), 'puzzle', 'document');
    }
}
