<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankImportController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PhoneCheckController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function (): void {
    Route::get('/', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->middleware('can:documents.upload')->name('documents.store');
    Route::post('documents/{id}/tags', [DocumentController::class, 'updateTags'])->middleware('can:documents.edit')->name('documents.tags');
    Route::get('files/{id}', [DocumentController::class, 'file'])->name('documents.file');
    Route::get('phone-check/qr', [PhoneCheckController::class, 'qr'])->name('phone-check.qr');

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

    Route::middleware('can:settings.manage')->group(function (): void {
        Route::get('update', [UpdateController::class, 'show'])->name('updates.show');
        Route::post('update/check', [UpdateController::class, 'check'])->middleware('throttle:10,1')->name('updates.check');
        Route::post('update/install', [UpdateController::class, 'install'])->middleware('throttle:10,1')->name('updates.install');
    });

    Route::middleware('can:tags.manage')->group(function (): void {
        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('tags', [TagController::class, 'store'])->name('tags.store');
        Route::post('tags/{id}', [TagController::class, 'update'])->name('tags.update');
        Route::post('tags/{id}/delete', [TagController::class, 'destroy'])->name('tags.destroy');
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
        Route::post('export', [ExportController::class, 'store'])->name('exports.store');
    });
});

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
