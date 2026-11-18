<?php

declare(strict_types=1);

use App\Http\Controllers\AccessKeyController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Assistant\AssistantSettingsController;
use App\Http\Controllers\Assistant\OAuthController;
use App\Http\Controllers\Assistant\ProposalController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankImportController;
use App\Http\Controllers\BankSettingsController;
use App\Http\Controllers\BankSuggestionsController;
use App\Http\Controllers\ConfirmController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardPhotoController;
use App\Http\Controllers\DeviceSettingsController;
use App\Http\Controllers\DocumentBulkController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\DocumentPackController;
use App\Http\Controllers\DocumentPropertyController;
use App\Http\Controllers\DocumentTransformController;
use App\Http\Controllers\DocumentUploadController;
use App\Http\Controllers\DocumentViewController;
use App\Http\Controllers\DocumentWordsController;
use App\Http\Controllers\DocumentZipController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\FolderImportController;
use App\Http\Controllers\GiroCodeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\NoteColumnController;
use App\Http\Controllers\OcrBrowserController;
use App\Http\Controllers\OcrController;
use App\Http\Controllers\OcrServerController;
use App\Http\Controllers\OcrStatusController;
use App\Http\Controllers\OptimizeController;
use App\Http\Controllers\PaletteController;
use App\Http\Controllers\PhoneCheckController;
use App\Http\Controllers\PrinterSettingsController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\QueueTickController;
use App\Http\Controllers\ReceiptPackController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SessionSettingsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\ShareTargetController;
use App\Http\Controllers\SpeedController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TextDocumentController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\UpdateController;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function (): void {
    // Point 176: Home is the dashboard (per person); point 175: the notes board has its own menu entry.
    Route::get('/', [DashboardController::class, 'index'])->name('home');
    Route::post('dashboard/layout', [DashboardController::class, 'layout'])->name('dashboard.layout');
    Route::post('dashboard/widgets', [DashboardController::class, 'add'])->name('dashboard.widgets.store');
    Route::post('dashboard/pins', [DashboardController::class, 'pin'])->name('dashboard.pins.store');
    Route::post('dashboard/pins/delete', [DashboardController::class, 'unpin'])->name('dashboard.pins.destroy');
    Route::post('dashboard/notes/pin', [DashboardController::class, 'pinNote'])->name('dashboard.notes.pin');
    Route::post('dashboard/first-steps/hide', [DashboardController::class, 'hideFirstSteps'])->name('dashboard.first-steps.hide');
    Route::get('dashboard/photo', [DashboardPhotoController::class, 'show'])->name('dashboard.photo');
    Route::post('dashboard/photo', [DashboardPhotoController::class, 'store'])->name('dashboard.photo.store');
    Route::post('dashboard/photo/delete', [DashboardPhotoController::class, 'destroy'])->name('dashboard.photo.destroy');
    Route::get('notes', [HomeController::class, 'index'])->name('notes.index');
    Route::get('badges', BadgeController::class)->name('badges');
    // Board columns first, so "notes/columns" is not taken for a note id.
    Route::post('notes/columns', [NoteColumnController::class, 'store'])->name('notes.columns.store');
    Route::post('notes/columns/order', [NoteColumnController::class, 'order'])->name('notes.columns.order');
    Route::post('notes/columns/{id}', [NoteColumnController::class, 'update'])->name('notes.columns.update');
    Route::post('notes/columns/{id}/look', [NoteColumnController::class, 'look'])->name('notes.columns.look');
    Route::post('notes/columns/{id}/delete', [NoteColumnController::class, 'destroy'])->name('notes.columns.destroy');
    Route::post('notes', [HomeController::class, 'store'])->name('notes.store');
    Route::post('notes/{id}', [HomeController::class, 'update'])->name('notes.update');
    Route::post('notes/{id}/look', [HomeController::class, 'look'])->name('notes.look');
    Route::post('notes/{id}/text', [HomeController::class, 'text'])->name('notes.text');
    Route::post('notes/{id}/move', [HomeController::class, 'move'])->name('notes.move');
    Route::post('notes/{id}/delete', [HomeController::class, 'destroy'])->name('notes.destroy');
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/trash', [DocumentController::class, 'trash'])->middleware('can:documents.trash')->name('documents.trash');
    // Drag and drop of documents (points 75-77): before documents/{id}/…, so "bulk" is no id.
    Route::post('documents/bulk/move', [DocumentBulkController::class, 'move'])->middleware('can:documents.edit')->name('documents.bulk.move');
    Route::post('documents/bulk/trash', [DocumentBulkController::class, 'trash'])->middleware('can:documents.trash')->name('documents.bulk.trash');
    Route::post('documents/bulk/restore', [DocumentBulkController::class, 'restore'])->middleware('can:documents.trash')->name('documents.bulk.restore');
    Route::post('documents/bulk/purge', [DocumentBulkController::class, 'purge'])->middleware('can:documents.trash')->name('documents.bulk.purge');
    Route::post('documents/trash/empty', [DocumentBulkController::class, 'empty'])->middleware('can:documents.trash')->name('documents.trash.empty');
    // Point 207: undo an emptying; the Papierkorb's settings (organisation-wide, Admins).
    Route::post('documents/trash/unempty', [TrashController::class, 'unempty'])->middleware('can:documents.trash')->name('documents.trash.unempty');
    Route::post('documents/trash/settings', [TrashController::class, 'settings'])->middleware('can:settings.manage')->name('documents.trash.settings');
    Route::post('documents/{id}/restore', [DocumentController::class, 'restore'])->middleware('can:documents.trash')->name('documents.restore');
    Route::post('documents/{id}/share-link', [ShareController::class, 'store'])->middleware('can:exports.create')->name('documents.share');
    Route::post('documents', [DocumentUploadController::class, 'store'])->middleware('can:documents.upload')->name('documents.store');
    // Points 31, A84: the Hochlade-Dialog's protocol into the Logbuch.
    Route::post('documents/upload-protocol', [DocumentUploadController::class, 'protocol'])->middleware(['can:documents.upload', 'throttle:30,1'])->name('documents.upload-protocol');
    // Text documents written in the app (point 21), before documents/{id} so "text" is no id.
    Route::post('documents/text', [TextDocumentController::class, 'store'])->middleware('can:documents.upload')->name('documents.text.store');
    Route::get('documents/{id}/text', [TextDocumentController::class, 'edit'])->name('documents.text');
    Route::post('documents/{id}/text', [TextDocumentController::class, 'update'])->middleware('can:documents.edit')->name('documents.text.update');
    Route::post('documents/view', [DocumentViewController::class, 'setView'])->name('documents.view');
    // A244: property columns of the details view, remembered per person (before documents/{id}, so "columns" is no id).
    Route::post('documents/columns', [DocumentViewController::class, 'setColumns'])->name('documents.columns');
    // Property values of a document (points 122.1, 122.4): card, popup "Markieren als", click-to-edit.
    Route::get('documents/{id}/properties', [DocumentPropertyController::class, 'card'])->name('documents.properties');
    // GiroCode card (point 120): the card again and its QR code, same visibility as the document.
    Route::get('documents/{id}/girocode', [GiroCodeController::class, 'card'])->name('documents.girocode');
    Route::get('documents/{id}/girocode.svg', [GiroCodeController::class, 'svg'])->name('documents.girocode.svg');
    Route::middleware('can:documents.edit')->group(function (): void {
        Route::post('documents/{id}/properties', [DocumentPropertyController::class, 'store'])->name('documents.properties.set');
        Route::post('documents/{id}/properties/preview', [DocumentPropertyController::class, 'preview'])->name('documents.properties.preview');
        Route::post('documents/{id}/properties/{property}/confirm', [DocumentPropertyController::class, 'confirm'])->name('documents.properties.confirm');
        Route::post('documents/{id}/properties/{property}/delete', [DocumentPropertyController::class, 'destroy'])->name('documents.properties.delete');
        // Point 172: the browser's translation of the recognised text, one per target language.
        Route::post('documents/{id}/translations', [TranslationController::class, 'store'])->name('documents.translations.store');
    });
    Route::get('documents/{id}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{id}/words', DocumentWordsController::class)->name('documents.words');
    // OCR corrections of single words by position and their undo (point 122.7, A243).
    Route::post('documents/{id}/words/fix', [DocumentWordsController::class, 'fix'])->middleware('can:documents.edit')->name('documents.words.fix');
    Route::post('documents/{id}/words/unfix', [DocumentWordsController::class, 'unfix'])->middleware('can:documents.edit')->name('documents.words.unfix');
    // Point 238: rotation and crop of the shown page (non-destructive).
    Route::post('documents/{id}/transform', DocumentTransformController::class)->middleware('can:documents.edit')->name('documents.transform');
    Route::post('documents/{id}', [DocumentController::class, 'update'])->middleware('can:documents.edit')->name('documents.update');
    Route::post('documents/{id}/field', [DocumentController::class, 'updateField'])->middleware('can:documents.edit')->name('documents.field');
    Route::post('documents/{id}/move', [DocumentController::class, 'move'])->middleware('can:documents.edit')->name('documents.move');
    Route::post('documents/{id}/delete', [DocumentController::class, 'destroy'])->middleware('can:documents.trash')->name('documents.destroy');
    Route::get('files/{id}/download', [DocumentFileController::class, 'download'])->name('documents.download');
    // Point 237: a folder branch or the picked documents as one streamed ZIP.
    Route::get('zip/folders/{id}', [DocumentZipController::class, 'folder'])->middleware('throttle:20,1')->name('folders.zip');
    Route::post('zip/documents', [DocumentZipController::class, 'picked'])->middleware('throttle:20,1')->name('documents.zip');
    Route::get('files/{id}/thumb', [DocumentFileController::class, 'thumb'])->name('documents.thumb');
    Route::get('tags.css', [TagController::class, 'css'])->name('tags.css');
    // Einstellungen → Log (point 115, A229-A234): all logbooks, one entry per logbook, export, reset; browser errors.
    Route::middleware('can:settings.manage')->group(function (): void {
        Route::get('settings/log', [LogController::class, 'index'])->name('log.index');
        Route::get('settings/log/general', [LogController::class, 'index'])->defaults('book', 'general')->name('log.general');
        Route::get('settings/log/ocr', [LogController::class, 'index'])->defaults('book', 'ocr')->name('log.ocr');
        Route::get('settings/log/updates', [LogController::class, 'index'])->defaults('book', 'updates')->name('log.updates');
        Route::get('settings/log/export', [LogController::class, 'export'])->name('log.export');
        Route::post('settings/log/reset', [LogController::class, 'reset'])->name('log.reset');
    });
    Route::post('log/client', [LogController::class, 'client'])->middleware('throttle:30,1')->name('log.client');
    // Text recognition set-up and browser fallback (point 18).
    Route::get('settings/ocr', [OcrController::class, 'show'])->middleware('can:settings.manage')->name('ocr.show');
    Route::post('settings/ocr', [OcrController::class, 'setup'])->middleware('can:settings.manage')->name('ocr.setup');
    Route::post('settings/ocr/rerun', [OcrController::class, 'rerun'])->middleware('can:settings.manage')->name('ocr.rerun');
    Route::post('documents/{id}/ocr', [OcrController::class, 'redo'])->middleware('can:documents.edit')->name('ocr.redo');
    // Point 186: server recognition on/off, "Server testen", "Mit Server neu erkennen".
    Route::post('settings/ocr/server', [OcrServerController::class, 'toggle'])->middleware('can:settings.manage')->name('ocr.server');
    Route::post('settings/ocr/test', [OcrServerController::class, 'test'])->middleware(['can:settings.manage', 'throttle:10,1'])->name('ocr.test');
    Route::post('documents/{id}/ocr/server', [OcrServerController::class, 'redo'])->middleware('can:documents.edit')->name('ocr.redo-server');
    // Point 236: "Dokumentgröße optimieren" – settings (saved at once) and its progress.
    Route::middleware('can:settings.manage')->prefix('settings/optimize')->name('optimize.')->controller(OptimizeController::class)->group(function (): void {
        Route::get('/', 'show')->name('show');
        Route::post('/', 'save')->name('save');
        Route::get('status', 'status')->name('status');
    });
    // Point 123: the same page as a module of its own (the OCR widget below Abmelden), without the settings columns.
    Route::get('ocr', [OcrController::class, 'show'])->middleware('can:settings.manage')->name('ocr.live');
    Route::get('ocr/status', [OcrStatusController::class, 'status'])->name('ocr.status');
    Route::get('ocr/next', [OcrBrowserController::class, 'next'])->name('ocr.next');
    Route::post('ocr/{id}/progress', [OcrBrowserController::class, 'progress'])->name('ocr.progress');
    Route::get('ocr/image/{id}', [OcrBrowserController::class, 'image'])->name('ocr.image');
    // 171.1: tesseract.js loads "<langPath>/<code>.traineddata" for every chosen language.
    Route::get('ocr/lang/{code}.traineddata', [OcrBrowserController::class, 'lang'])->where('code', '[a-z_]{3,10}')->name('ocr.lang');
    Route::post('ocr/{id}', [OcrBrowserController::class, 'store'])->name('ocr.store');
    // 172.1: runtime and model files of the installed translation packs for the browser (catalogue names only).
    Route::get('translate/files/{key}/{name}', [TranslationController::class, 'file'])->where(['key' => '[a-z0-9-]+', 'name' => '[A-Za-z0-9._-]+'])->name('translate.file');
    Route::post('ocr-engine', [OcrStatusController::class, 'engineError'])->name('ocr.engine');
    Route::post('ocr-log', [OcrStatusController::class, 'log'])->name('ocr.log');
    // A69/A74: without cron the open browser works off the queue, one job per call.
    Route::post('queue/tick', QueueTickController::class)->name('queue.tick');
    // Einstellungen → Eigenschaften (point 122.2; before settings/{group}): org-wide, so only Admins (settings.manage).
    Route::middleware('can:settings.manage')->prefix('settings/properties')->name('properties.')->group(function (): void {
        Route::get('/', [PropertyController::class, 'index'])->name('index');
        Route::post('/', [PropertyController::class, 'store'])->name('store');
        Route::post('order', [PropertyController::class, 'order'])->name('order');
        Route::post('{id}', [PropertyController::class, 'update'])->name('update');
        Route::post('{id}/delete', [PropertyController::class, 'destroy'])->name('destroy');
    });

    // Einstellungen → Eingang (point 153.5; before settings/{group}): the sources of the Posteingang.
    // Drucker (point 127): each person's own computers with the printer "Hamster"; the old address redirects.
    Route::redirect('settings/printer', '/settings/eingang/printer');
    Route::view('settings/eingang/mail', 'settings.mail')->middleware('can:documents.upload')->name('inbox.mail');
    Route::middleware('can:documents.upload')->prefix('settings/eingang/printer')->name('printers.')->group(function (): void {
        Route::get('/', [PrinterSettingsController::class, 'index'])->name('index');
        Route::post('setup', [PrinterSettingsController::class, 'setup'])->name('setup');
        Route::post('{id}/delete', [PrinterSettingsController::class, 'destroy'])->name('destroy');
    });

    // Einstellungen → Geräte (points 152, A329, A330; before settings/{group}): upload keys for an iPhone shortcut or a program.
    Route::middleware('can:documents.upload')->prefix('settings/devices')->name('devices.')->group(function (): void {
        Route::get('/', [DeviceSettingsController::class, 'index'])->name('index');
        Route::post('/', [DeviceSettingsController::class, 'store'])->name('store');
        Route::post('{id}/delete', [DeviceSettingsController::class, 'destroy'])->name('destroy');
        Route::post('{id}/rekey', [DeviceSettingsController::class, 'rekey'])->name('rekey');
    });
    // Einstellungen → Mein Konto (point 222; before settings/{group}): own name, password and sign-ins – everyone.
    Route::prefix('settings/account')->group(function (): void {
        Route::get('/', [AccountController::class, 'show'])->name('account.show');
        Route::post('name', [AccountController::class, 'name'])->name('account.name');
        // The redirect target after a sign-in with a one-time password (point 222.4).
        Route::get('password', [AccountController::class, 'password'])->name('account.password');
        Route::post('password', [AccountController::class, 'updatePassword'])->middleware('throttle:10,1')->name('account.password.update');
        // Point 208: Angemeldete Geräte – end one sign-in or all others (the person's own sessions only).
        Route::post('sessions/others', [SessionSettingsController::class, 'destroyOthers'])->middleware('throttle:10,1')->name('sessions.others');
        Route::post('sessions/{id}/end', [SessionSettingsController::class, 'destroy'])->name('sessions.end');
        // D-040: Zugangsschlüssel for the interface (api/v1) – the person's own keys.
        Route::post('keys', [AccessKeyController::class, 'store'])->middleware('throttle:10,1')->name('access-keys.store');
        Route::post('keys/{id}/delete', [AccessKeyController::class, 'destroy'])->name('access-keys.destroy');
    });
    // D-040: Einstellungen → Assistenten (owner only; before settings/{group}) and an assistant's consent page.
    // A57: the address is also the Einstellungen group "Assistenten" – a role with only "Vorschläge bearbeiten" is sent on.
    Route::get('settings/assistant', [AssistantSettingsController::class, 'show'])->middleware('can:proposals.view')->name('assistant.show');
    Route::middleware('can:assistant.manage')->prefix('settings/assistant')->name('assistant.')->controller(AssistantSettingsController::class)->group(function (): void {
        Route::post('/', 'save')->name('save');
        Route::post('{id}/disconnect', 'disconnect')->name('disconnect');
    });
    // D-040: Vorschläge – the assistant's proposals, taken over or not by the owner.
    Route::middleware('can:proposals.view')->prefix('proposals')->name('proposals.')->controller(ProposalController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('all', 'acceptAll')->name('accept-all');
        Route::post('{id}/accept', 'accept')->name('accept');
        Route::post('{id}/reject', 'reject')->name('reject');
        Route::get('{id}/preview/{side}', 'preview')->whereIn('side', ['before', 'after'])->name('preview');
    });
    Route::get('oauth/authorize', [OAuthController::class, 'authorize'])->name('oauth.authorize');
    Route::post('oauth/authorize', [OAuthController::class, 'approve'])->name('oauth.approve');

    // "Teilen → DocHamster" (points 151, A328): the service worker keeps the share, this page sends it (also after login).
    Route::middleware('can:documents.upload')->prefix('share')->name('share.')->group(function (): void {
        Route::get('/', [ShareTargetController::class, 'receive'])->name('receive');
        Route::post('/', [ShareTargetController::class, 'store'])->name('store');
        Route::get('done', [ShareTargetController::class, 'done'])->name('done');
        Route::post('done', [ShareTargetController::class, 'update'])->middleware('can:documents.edit')->name('update');
    });

    // Einstellungen → Erweiterungen (point 173; before settings/{group}): optional data packs and modules, catalogue only.
    Route::middleware('can:settings.manage')->prefix('settings/extensions')->name('extensions.')->controller(ExtensionController::class)->group(function (): void {
        Route::get('/', 'show')->name('index');
        Route::post('ocr-rerun', 'rerunBroken')->name('rerun');
        Route::post('{key}/install', 'install')->where('key', '[a-z0-9-]+')->name('install');
        Route::post('{key}/remove', 'remove')->where('key', '[a-z0-9-]+')->name('remove');
        Route::post('{key}/toggle', 'toggle')->where('key', '[a-z0-9-]+')->name('toggle');
    });

    // Einstellungen → Geschwindigkeit (point 164.7; before settings/{group}): measurements, checks, self tests, options.
    Route::middleware('can:settings.manage')->prefix('settings/speed')->name('speed.')->controller(SpeedController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('options', 'options')->name('options');
        Route::post('optimize', 'optimize')->name('optimize');
        Route::post('reset', 'reset')->name('reset');
        Route::get('db', 'db')->name('db');
        Route::get('download', 'download')->name('download');
    });
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('settings/{group}', [SettingsController::class, 'group'])->name('settings.group');
    Route::post('documents/{id}/tags', [DocumentController::class, 'updateTags'])->middleware('can:documents.edit')->name('documents.tags');
    Route::get('files/{id}', [DocumentFileController::class, 'file'])->name('documents.file');
    Route::get('phone-check/qr', [PhoneCheckController::class, 'qr'])->name('phone-check.qr');

    // Posteingang (point 153): everything that came in; the old Druckerablage and E-Mail-Ablage redirect to its tabs.
    Route::middleware('can:documents.upload')->group(function (): void {
        Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::post('inbox/done', [InboxController::class, 'doneAll'])->name('inbox.done-all');
        Route::post('inbox/{id}/done', [InboxController::class, 'done'])->name('inbox.done');
        Route::redirect('print-tray', '/inbox?source=printer');
        Route::redirect('mail-tray', '/inbox?source=mail');
    });

    Route::middleware('can:members.manage')->group(function (): void {
        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::post('members', [MemberController::class, 'store'])->name('members.store');
        Route::post('members/{id}/reset', [MemberController::class, 'resetPassword'])->name('members.reset');
        Route::post('members/{id}/revoke', [MemberController::class, 'revoke'])->name('members.revoke');
        Route::post('members/{id}/role', [MemberController::class, 'changeRole'])->name('members.role');
        // Points 218, 223: "Zugang von / bis" and "Pausiert" of one person.
        Route::post('members/{id}/access', [MemberController::class, 'access'])->name('members.access');
        Route::post('members/{id}/pause', [MemberController::class, 'pause'])->name('members.pause');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::post('roles/{id}', [RoleController::class, 'update'])->name('roles.update');
        Route::post('roles/{id}/delete', [RoleController::class, 'destroy'])->name('roles.destroy');
        // Points 215.3, 223: the restrictions and the pause of a role.
        Route::post('roles/{id}/restrict', [RoleController::class, 'restrict'])->name('roles.restrict');
        Route::post('roles/{id}/pause', [RoleController::class, 'pause'])->name('roles.pause');
    });

    Route::middleware('can:system.update')->group(function (): void {
        Route::get('update', [UpdateController::class, 'show'])->name('updates.show');
        Route::post('update/check', [UpdateController::class, 'check'])->name('updates.check');
        Route::post('update/install', [UpdateController::class, 'install'])->name('updates.install');
        Route::post('update/install-main', [UpdateController::class, 'installMain'])->name('updates.install-main');
        Route::middleware('can:system.update_preview')->group(function (): void {
            Route::post('update/install-preview', [UpdateController::class, 'installPreview'])->name('updates.install-preview');
        });
    });

    // D-041: modules on the Software page – owner only; install, update and remove ask for a fresh password.
    Route::middleware('can:modules.manage')->prefix('settings/modules')->name('modules.')->controller(ModuleController::class)->group(function (): void {
        Route::post('find', 'find')->middleware('throttle:20,1')->name('find');
        Route::post('partners', 'partners')->middleware('throttle:10,1')->name('partners');
        Route::post('install', 'install')->name('install');
        Route::post('{key}/update', 'update')->where('key', '[a-z0-9-]+')->name('update');
        Route::post('{key}/toggle', 'toggle')->where('key', '[a-z0-9-]+')->name('toggle');
        Route::post('{key}/remove', 'remove')->where('key', '[a-z0-9-]+')->name('remove');
    });

    // Shared colour palette (points 14-15): org-wide, so only Admins (settings.manage).
    Route::middleware('can:settings.manage')->prefix('palette')->name('palette.')->group(function (): void {
        Route::get('/', [PaletteController::class, 'index'])->name('index');
        Route::post('/', [PaletteController::class, 'store'])->name('store');
        Route::post('order', [PaletteController::class, 'order'])->name('order');
        Route::post('{id}', [PaletteController::class, 'update'])->name('update');
        Route::post('{id}/delete', [PaletteController::class, 'destroy'])->name('destroy');
    });

    Route::middleware('can:tags.manage')->group(function (): void {
        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('tags', [TagController::class, 'store'])->name('tags.store');
        Route::post('tags/{id}', [TagController::class, 'update'])->name('tags.update');
        Route::post('tags/{id}/delete', [TagController::class, 'destroy'])->name('tags.destroy');
    });

    Route::middleware('can:documents.edit')->group(function (): void {
        Route::get('folders', [FolderController::class, 'index'])->name('folders.index');
        Route::post('folders', [FolderController::class, 'store'])->name('folders.store');
        Route::post('folders/order', [FolderController::class, 'order'])->name('folders.order');
        Route::post('folders/trash', [FolderController::class, 'trash'])->middleware('can:documents.trash')->name('folders.trash');
        Route::post('folders/{id}/move', [FolderController::class, 'move'])->name('folders.move');
        Route::post('folders/{id}/step', [FolderController::class, 'step'])->name('folders.step');
        Route::post('folders/import', [FolderImportController::class, 'start'])->middleware('can:documents.upload')->name('folders.import');
        Route::post('folders/import/{id}/file', [FolderImportController::class, 'file'])->middleware('can:documents.upload')->name('folders.import.file');
        Route::post('folders/{id}', [FolderController::class, 'update'])->name('folders.update');
        Route::post('folders/{id}/delete', [FolderController::class, 'destroy'])->name('folders.destroy');
    });

    // F40: "Bank ansehen" opens the Bank module read-only; everything that imports, edits or matches needs "Bank".
    Route::middleware('can:banking.view')->prefix('bank')->name('bank.')->group(function (): void {
        Route::get('/', [BankController::class, 'index'])->name('index');
        Route::get('accounts/{id}', [BankController::class, 'account'])->name('account');
        Route::get('receipts', [BankController::class, 'receipts'])->name('receipts');
        Route::get('transactions/{id}', [BankController::class, 'show'])->name('show');
    });

    Route::middleware('can:banking.import')->prefix('bank')->name('bank.')->group(function (): void {
        Route::get('import', [BankImportController::class, 'form'])->name('import');
        Route::post('import', [BankImportController::class, 'upload'])->name('import.upload');
        Route::get('import/columns', [BankImportController::class, 'columns'])->name('import.columns');
        Route::post('import/columns', [BankImportController::class, 'run'])->name('import.run');
        Route::get('import/preview', [BankImportController::class, 'preview'])->name('import.preview');
        Route::post('import/preview', [BankImportController::class, 'confirm'])->name('import.confirm');
        Route::post('accounts/{id}/rename', [BankImportController::class, 'rename'])->name('accounts.rename');
        // Points 189, 190: accounts set up by hand, the kind, cash entries.
        Route::post('accounts', [BankAccountController::class, 'store'])->name('accounts.store');
        Route::post('accounts/{id}/kind', [BankAccountController::class, 'kind'])->name('accounts.kind');
        Route::post('accounts/{id}/entries', [BankAccountController::class, 'storeEntry'])->name('cash.store');
        Route::post('entries/{id}', [BankAccountController::class, 'updateEntry'])->name('cash.update');
        Route::post('entries/{id}/delete', [BankAccountController::class, 'destroyEntry'])->name('cash.destroy');
        // Einstellungen → Bank: reasons for "kein Beleg nötig" (A286) and MCC → tag (A300).
        Route::get('reasons', [BankSettingsController::class, 'reasons'])->name('reasons');
        Route::post('reasons', [BankSettingsController::class, 'storeReason'])->name('reasons.store');
        Route::post('reasons/order', [BankSettingsController::class, 'orderReasons'])->name('reasons.order');
        Route::post('reasons/{id}', [BankSettingsController::class, 'updateReason'])->name('reasons.update');
        Route::post('reasons/{id}/delete', [BankSettingsController::class, 'destroyReason'])->name('reasons.destroy');
        Route::get('mcc', [BankSettingsController::class, 'mcc'])->name('mcc');
        Route::post('mcc', [BankSettingsController::class, 'storeMcc'])->name('mcc.store');
        Route::post('mcc/{id}/delete', [BankSettingsController::class, 'destroyMcc'])->name('mcc.destroy');
        Route::post('accounts/{id}/delete', [BankImportController::class, 'destroyAccount'])->name('accounts.destroy');
        Route::get('transactions/{id}/suggestions', [BankSuggestionsController::class, 'show'])->name('suggestions');
        Route::post('transactions/{id}/match', [BankController::class, 'match'])->name('match');
        Route::post('transactions/{id}/link', [BankController::class, 'link'])->name('link');
        Route::post('transactions/{id}/confirm', [BankController::class, 'confirm'])->name('confirm');
        Route::post('transactions/{id}/reject', [BankController::class, 'reject'])->name('reject');
        Route::post('transactions/{id}/unmatch', [BankController::class, 'unmatch'])->name('unmatch');
        Route::post('transactions/{id}/unlink', [BankController::class, 'unlink'])->name('unlink');
        Route::post('transactions/{id}/no-receipt', [BankController::class, 'noReceipt'])->name('no-receipt');
        Route::post('transactions/{id}/tag', [BankController::class, 'tag'])->name('tag');
        Route::post('transactions/{id}/note', [BankController::class, 'note'])->name('note');
        Route::post('standing/{id}/end', [BankController::class, 'endStanding'])->name('standing.end');
    });

    Route::middleware('can:exports.create')->group(function (): void {
        Route::get('export', [ExportController::class, 'form'])->name('exports.form');
        Route::get('share-links', [ShareController::class, 'index'])->name('shares.index');
        // The lifetime holds for the whole organisation, so only Admins set it (#56).
        Route::post('share-links/days', [ShareController::class, 'days'])->middleware('can:settings.manage')->name('shares.days');
        Route::post('share-links/{id}/revoke', [ShareController::class, 'revoke'])->name('shares.revoke');
        Route::post('export', [ExportController::class, 'store'])->name('exports.store');
    });
});

// A receipt handed out by link (A4): no login, until it expires or is revoked.
Route::get('s/{token}', [ShareController::class, 'open'])->middleware('throttle:30,1')->name('shares.open');

// Temporary device probe (#37): open like the later home-screen page, holds no data.
Route::get('phone-check', [PhoneCheckController::class, 'show'])->name('phone-check');
Route::get('phone-check/manifest', [PhoneCheckController::class, 'manifest'])->name('phone-check.manifest');

// The app's web app manifest (point 151): installable, share target on Android.
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('manifest');

// Setup wizard (D-024); RedirectToSetup sends everyone here until installed, then returns 404.
Route::prefix('setup')->name('setup.')->controller(SetupController::class)->group(function (): void {
    Route::get('/', 'welcome')->name('welcome');
    Route::post('unlock', 'unlock')->middleware('throttle:setup')->name('unlock');
    Route::get('server', 'server')->name('server');
    Route::post('limits', 'fixLimits')->name('limits');
    Route::get('database', 'databaseForm')->name('database');
    Route::post('database', 'database')->name('database.save');
    Route::get('finish', 'finishPage')->name('finish');
    Route::post('finish', 'finish')->name('finish.save');
});

Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login')->name('login.attempt');
});
Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// Point 206 (D-023): Einstellungen → Backup – Admins only; start and download ask for the password again.
Route::middleware(['auth', 'org', 'can:backup.create'])->prefix('backup')->name('backup.')->controller(BackupController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('estimate', 'estimate')->name('estimate');
    Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
    Route::post('{id}/download', 'download')->middleware('throttle:10,1')->name('download');
});

// Points 211, 215: the Belegpaket – seen and downloaded with "Belegpakete", made with "Exportieren" + "Alle Dokumente sehen".
Route::middleware(['auth', 'org'])->group(function (): void {
    Route::middleware('can:packs.view')->prefix('export/packs')->name('packs.')->controller(ReceiptPackController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware(['can:exports.create', 'can:documents.view_all', 'throttle:10,1', 'confirmed'])->name('store');
        Route::get('{id}/download', 'download')->name('download');
    });
    Route::post('documents/{id}/receipt-pack', DocumentPackController::class)->middleware('can:documents.edit')->name('documents.receipt-pack');
});
// Points 210.3, 215: "Passwort vergessen?" for everyone with an e-mail (only where mail works).
Route::middleware('guest')->controller(PasswordResetController::class)->group(function (): void {
    Route::get('password/forgot', 'forgot')->name('password.request');
    Route::post('password/forgot', 'send')->middleware('throttle:5,1')->name('password.email');
    Route::get('password/reset/{token}', 'resetForm')->name('password.reset');
    Route::post('password/reset', 'reset')->middleware('throttle:10,1')->name('password.store');
});
// Point 212: "Bitte bestätigen" (sudo mode) – the dialog's two routes, and the middleware `confirmed` on every
// sensitive action listed in RequireRecentConfirmation::ROUTES (new routes may also add ->middleware('confirmed')).
Route::middleware(['auth', 'org'])->prefix('confirm')->name('confirm.')->controller(ConfirmController::class)->group(function (): void {
    Route::get('status', 'status')->name('status');
    Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
});
Route::getRoutes()->refreshNameLookups();
foreach (RequireRecentConfirmation::ROUTES as $confirmedRoute) {
    Route::getRoutes()->getByName($confirmedRoute)?->middleware(RequireRecentConfirmation::ALIAS);
}
