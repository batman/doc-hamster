<?php

declare(strict_types=1);

use App\Http\Controllers\AppShellController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankImportController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PhoneAppController;
use App\Http\Controllers\PhoneCheckController;
use App\Http\Controllers\PhoneController;
use App\Http\Controllers\PrintTrayController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('badges', BadgeController::class)->name('badges');
    Route::post('notes', [HomeController::class, 'store'])->name('notes.store');
    Route::post('notes/{id}', [HomeController::class, 'update'])->name('notes.update');
    Route::post('notes/{id}/look', [HomeController::class, 'look'])->name('notes.look');
    Route::post('notes/{id}/check', [HomeController::class, 'check'])->name('notes.check');
    Route::post('notes/{id}/delete', [HomeController::class, 'destroy'])->name('notes.destroy');
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/trash', [DocumentController::class, 'trash'])->middleware('can:documents.trash')->name('documents.trash');
    Route::post('documents/{id}/restore', [DocumentController::class, 'restore'])->middleware('can:documents.trash')->name('documents.restore');
    Route::post('documents/{id}/share-link', [ShareController::class, 'store'])->middleware('can:exports.create')->name('documents.share');
    Route::post('documents', [DocumentController::class, 'store'])->middleware('can:documents.upload')->name('documents.store');
    Route::post('documents/view', [DocumentController::class, 'setView'])->name('documents.view');
    Route::get('documents/{id}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('documents/{id}', [DocumentController::class, 'update'])->middleware('can:documents.edit')->name('documents.update');
    Route::post('documents/{id}/delete', [DocumentController::class, 'destroy'])->middleware('can:documents.trash')->name('documents.destroy');
    Route::get('files/{id}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::post('files/{id}/print', [DocumentController::class, 'printOnPrinter'])->middleware('throttle:20,1')->name('documents.print');
    Route::get('files/{id}/thumb', [DocumentController::class, 'thumb'])->name('documents.thumb');
    Route::get('tags.css', [TagController::class, 'css'])->name('tags.css');
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('settings/{group}', [SettingsController::class, 'group'])->name('settings.group');
    Route::post('documents/{id}/tags', [DocumentController::class, 'updateTags'])->middleware('can:documents.edit')->name('documents.tags');
    Route::get('files/{id}', [DocumentController::class, 'file'])->name('documents.file');
    Route::get('phone-check/qr', [PhoneCheckController::class, 'qr'])->name('phone-check.qr');

    // The phone app (PHONE, #37): opened from the home screen. Belege = camera, Dokumente = folders, documents, scanning.
    Route::prefix('app')->name('app.')->controller(PhoneAppController::class)->group(function (): void {
        Route::get('/', 'camera')->name('home');
        Route::get('documents', 'documents')->name('documents');
        Route::get('documents/{id}', 'document')->name('documents.show');
        Route::get('scan', 'scan')->name('scan');
        Route::get('scanner', 'scanner')->name('scanner');
        Route::get('settings', 'settings')->name('settings');
    });

    // Handy verbinden: QR code once, then the phone stays signed in (PLAN §11.1).
    Route::get('phone', [PhoneController::class, 'index'])->name('phone.index');
    Route::post('phone/code', [PhoneController::class, 'start'])->middleware('throttle:10,1')->name('phone.start');
    Route::post('phone/devices/{id}/rename', [PhoneController::class, 'rename'])->name('phone.rename');
    Route::post('phone/devices/{id}/revoke', [PhoneController::class, 'revoke'])->name('phone.revoke');

    // Druckerablage: what was printed on "Hamster" (print tray).
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
        Route::post('update/check', [UpdateController::class, 'check'])->middleware('throttle:10,1')->name('updates.check');
        Route::post('update/install', [UpdateController::class, 'install'])->middleware('throttle:10,1')->name('updates.install');
        Route::post('update/install-main', [UpdateController::class, 'installMain'])->middleware('throttle:10,1')->name('updates.install-main');
        Route::middleware('can:system.update_preview')->group(function (): void {
            Route::post('update/previews', [UpdateController::class, 'checkPreviews'])->middleware('throttle:10,1')->name('updates.previews');
            Route::post('update/install-preview', [UpdateController::class, 'installPreview'])->middleware('throttle:10,1')->name('updates.install-preview');
        });
    });

    Route::middleware('can:tags.manage')->group(function (): void {
        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('tags', [TagController::class, 'store'])->name('tags.store');
        Route::post('tags/{id}', [TagController::class, 'update'])->name('tags.update');
        Route::post('tags/{id}/delete', [TagController::class, 'destroy'])->name('tags.destroy');
    });

    Route::middleware('can:documents.upload')->prefix('scan')->name('scan.')->group(function (): void {
        Route::get('/', [ScanController::class, 'index'])->name('index');
        Route::post('page', [ScanController::class, 'scan'])->middleware('throttle:30,1')->name('page');
        Route::post('finish', [ScanController::class, 'finish'])->name('finish');
        Route::post('cancel', [ScanController::class, 'cancel'])->name('cancel');
        Route::get('pages/{number}', [ScanController::class, 'page'])->whereNumber('number')->name('preview');
        Route::post('pages/{number}/delete', [ScanController::class, 'remove'])->whereNumber('number')->name('remove');
    });

    // Drucker & Scanner (A33): everyone who may upload connects a device; removing one stays with admins.
    Route::middleware('can:documents.upload')->group(function (): void {
        Route::get('scanner', [ScanController::class, 'settings'])->name('scanner.show');
        Route::post('scanner', [ScanController::class, 'save'])->middleware('throttle:10,1')->name('scanner.save');
        Route::post('scanner/search', [ScanController::class, 'search'])->middleware('throttle:5,1')->name('scanner.search');
        Route::post('scanner/stations', [ScanController::class, 'stationCreate'])->middleware('throttle:10,1')->name('scanner.station.create');
        Route::get('scanner/station-download', [ScanController::class, 'stationDownload'])->name('scanner.station.download');
    });
    Route::middleware('can:settings.manage')->group(function (): void {
        Route::post('scanner/stations/{id}/revoke', [ScanController::class, 'stationRevoke'])->name('scanner.station.revoke');
    });

    Route::middleware('can:documents.edit')->group(function (): void {
        Route::get('folders', [FolderController::class, 'index'])->name('folders.index');
        Route::post('folders', [FolderController::class, 'store'])->name('folders.store');
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

// The home-screen app (PHONE, #37): manifest and the page for "no connection"; no login, no data.
Route::get('manifest.webmanifest', [AppShellController::class, 'manifest'])->name('app.manifest');
Route::get('offline', [AppShellController::class, 'offline'])->name('app.offline');

// The phone opens the QR code here; signed in or not (PhoneController::connect).
Route::get('connect/{code?}', [PhoneController::class, 'connectForm'])->where('code', '[A-Za-z0-9-]{1,20}')->name('phone.connect');
Route::post('connect', [PhoneController::class, 'connect'])->middleware('throttle:pairing')->name('phone.connect.save');

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
