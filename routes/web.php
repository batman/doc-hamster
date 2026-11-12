<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankImportController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\FolderImportController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\NoteColumnController;
use App\Http\Controllers\OcrController;
use App\Http\Controllers\PaletteController;
use App\Http\Controllers\PhoneCheckController;
use App\Http\Controllers\PrintTrayController;
use App\Http\Controllers\QueueTickController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TextDocumentController;
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
    Route::post('notes/columns/{id}/archive', [NoteColumnController::class, 'archive'])->name('notes.columns.archive');
    Route::post('notes/columns/{id}/delete', [NoteColumnController::class, 'destroy'])->name('notes.columns.destroy');
    Route::get('notes/archive', [HomeController::class, 'archive'])->name('notes.archive');
    Route::post('notes', [HomeController::class, 'store'])->name('notes.store');
    Route::post('notes/{id}', [HomeController::class, 'update'])->name('notes.update');
    Route::post('notes/{id}/look', [HomeController::class, 'look'])->name('notes.look');
    Route::post('notes/{id}/text', [HomeController::class, 'text'])->name('notes.text');
    Route::post('notes/{id}/move', [HomeController::class, 'move'])->name('notes.move');
    Route::post('notes/{id}/archive', [HomeController::class, 'archiveNote'])->name('notes.archive_note');
    Route::post('notes/{id}/restore', [HomeController::class, 'restore'])->name('notes.restore');
    Route::post('notes/{id}/delete', [HomeController::class, 'destroy'])->name('notes.destroy');
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/trash', [DocumentController::class, 'trash'])->middleware('can:documents.trash')->name('documents.trash');
    Route::post('documents/{id}/restore', [DocumentController::class, 'restore'])->middleware('can:documents.trash')->name('documents.restore');
    Route::post('documents/{id}/share-link', [ShareController::class, 'store'])->middleware('can:exports.create')->name('documents.share');
    Route::post('documents', [DocumentController::class, 'store'])->middleware('can:documents.upload')->name('documents.store');
    // Text documents written in the app (point 21), before documents/{id} so "text" is no id.
    Route::post('documents/text', [TextDocumentController::class, 'store'])->middleware('can:documents.upload')->name('documents.text.store');
    Route::get('documents/{id}/text', [TextDocumentController::class, 'edit'])->name('documents.text');
    Route::post('documents/{id}/text', [TextDocumentController::class, 'update'])->middleware('can:documents.edit')->name('documents.text.update');
    Route::post('documents/view', [DocumentController::class, 'setView'])->name('documents.view');
    Route::get('documents/{id}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('documents/{id}', [DocumentController::class, 'update'])->middleware('can:documents.edit')->name('documents.update');
    Route::post('documents/{id}/field', [DocumentController::class, 'updateField'])->middleware('can:documents.edit')->name('documents.field');
    Route::post('documents/{id}/move', [DocumentController::class, 'move'])->middleware('can:documents.edit')->name('documents.move');
    Route::post('documents/{id}/delete', [DocumentController::class, 'destroy'])->middleware('can:documents.trash')->name('documents.destroy');
    Route::get('files/{id}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('files/{id}/thumb', [DocumentController::class, 'thumb'])->name('documents.thumb');
    Route::get('tags.css', [TagController::class, 'css'])->name('tags.css');
    // Text recognition set-up and browser fallback (point 18).
    Route::get('settings/ocr', [OcrController::class, 'show'])->middleware('can:settings.manage')->name('ocr.show');
    Route::post('settings/ocr', [OcrController::class, 'setup'])->middleware('can:settings.manage')->name('ocr.setup');
    Route::post('settings/ocr/rerun', [OcrController::class, 'rerun'])->middleware('can:settings.manage')->name('ocr.rerun');
    Route::post('documents/{id}/ocr', [OcrController::class, 'redo'])->middleware('can:documents.edit')->name('ocr.redo');
    Route::get('ocr/status', [OcrController::class, 'status'])->name('ocr.status');
    Route::get('ocr/next', [OcrController::class, 'next'])->name('ocr.next');
    Route::post('ocr/{id}/progress', [OcrController::class, 'progress'])->name('ocr.progress');
    Route::get('ocr/image/{id}', [OcrController::class, 'image'])->name('ocr.image');
    Route::post('ocr/{id}', [OcrController::class, 'store'])->name('ocr.store');
    Route::post('ocr-engine', [OcrController::class, 'engineError'])->name('ocr.engine');
    Route::post('ocr-log', [OcrController::class, 'log'])->name('ocr.log');
    // A69/A74: without cron the open browser works off the queue, one job per call.
    Route::post('queue/tick', QueueTickController::class)->name('queue.tick');
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('settings/{group}', [SettingsController::class, 'group'])->name('settings.group');
    Route::post('documents/{id}/tags', [DocumentController::class, 'updateTags'])->middleware('can:documents.edit')->name('documents.tags');
    Route::get('files/{id}', [DocumentController::class, 'file'])->name('documents.file');
    Route::get('phone-check/qr', [PhoneCheckController::class, 'qr'])->name('phone-check.qr');

    // Druckerablage: what was printed on "Hamster" (print tray).
    // E-Mail-Ablage (point 30): placeholder until the function comes.
    Route::view('mail-tray', 'mail_tray.index')->middleware('can:documents.upload')->name('mail-tray.index');
    Route::middleware('can:documents.upload')->prefix('print-tray')->name('print-tray.')->group(function (): void {
        Route::get('/', [PrintTrayController::class, 'index'])->name('index');
        Route::post('printers', [PrintTrayController::class, 'setup'])->name('setup');
        Route::post('printers/{id}/revoke', [PrintTrayController::class, 'revoke'])->name('revoke');
        Route::post('{id}/accept', [PrintTrayController::class, 'accept'])->name('accept');
        Route::post('{id}/delete', [PrintTrayController::class, 'destroy'])->middleware('can:documents.trash')->name('destroy');
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
            Route::post('update/previews', [UpdateController::class, 'checkPreviews'])->name('updates.previews');
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
        Route::post('accounts/{id}/delete', [BankImportController::class, 'destroyAccount'])->name('accounts.destroy');
        Route::get('transactions/{id}', [BankController::class, 'show'])->name('show');
        Route::post('transactions/{id}/match', [BankController::class, 'match'])->name('match');
        Route::post('transactions/{id}/unmatch', [BankController::class, 'unmatch'])->name('unmatch');
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
