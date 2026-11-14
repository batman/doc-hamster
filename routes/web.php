<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankImportController;
use App\Http\Controllers\BankSettingsController;
use App\Http\Controllers\DeviceSettingsController;
use App\Http\Controllers\DocumentBulkController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\DocumentPropertyController;
use App\Http\Controllers\DocumentUploadController;
use App\Http\Controllers\DocumentViewController;
use App\Http\Controllers\DocumentWordsController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\FolderImportController;
use App\Http\Controllers\GiroCodeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\NoteColumnController;
use App\Http\Controllers\OcrBrowserController;
use App\Http\Controllers\OcrController;
use App\Http\Controllers\OcrStatusController;
use App\Http\Controllers\PaletteController;
use App\Http\Controllers\PhoneCheckController;
use App\Http\Controllers\PrinterSettingsController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\QueueTickController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\ShareTargetController;
use App\Http\Controllers\SpeedController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TextDocumentController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('home');
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
    Route::post('documents/{id}/restore', [DocumentController::class, 'restore'])->middleware('can:documents.trash')->name('documents.restore');
    Route::post('documents/{id}/share-link', [ShareController::class, 'store'])->middleware('can:exports.create')->name('documents.share');
    Route::post('documents', [DocumentUploadController::class, 'store'])->middleware('can:documents.upload')->name('documents.store');
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
    Route::post('documents/{id}', [DocumentController::class, 'update'])->middleware('can:documents.edit')->name('documents.update');
    Route::post('documents/{id}/field', [DocumentController::class, 'updateField'])->middleware('can:documents.edit')->name('documents.field');
    Route::post('documents/{id}/move', [DocumentController::class, 'move'])->middleware('can:documents.edit')->name('documents.move');
    Route::post('documents/{id}/delete', [DocumentController::class, 'destroy'])->middleware('can:documents.trash')->name('documents.destroy');
    Route::get('files/{id}/download', [DocumentFileController::class, 'download'])->name('documents.download');
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
    });

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

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::post('roles/{id}', [RoleController::class, 'update'])->name('roles.update');
        Route::post('roles/{id}/delete', [RoleController::class, 'destroy'])->name('roles.destroy');
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

    Route::middleware('can:banking.import')->prefix('bank')->name('bank.')->group(function (): void {
        Route::get('/', [BankController::class, 'index'])->name('index');
        Route::get('import', [BankImportController::class, 'form'])->name('import');
        Route::post('import', [BankImportController::class, 'upload'])->name('import.upload');
        Route::get('import/columns', [BankImportController::class, 'columns'])->name('import.columns');
        Route::post('import/columns', [BankImportController::class, 'run'])->name('import.run');
        Route::get('import/preview', [BankImportController::class, 'preview'])->name('import.preview');
        Route::post('import/preview', [BankImportController::class, 'confirm'])->name('import.confirm');
        Route::post('accounts/{id}/rename', [BankImportController::class, 'rename'])->name('accounts.rename');
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
        Route::get('accounts/{id}', [BankController::class, 'account'])->name('account');
        Route::get('receipts', [BankController::class, 'receipts'])->name('receipts');
        Route::get('transactions/{id}', [BankController::class, 'show'])->name('show');
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
