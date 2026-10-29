<?php

declare(strict_types=1);

use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

// Setup wizard (D-024); RedirectToSetup sends everyone here until installed, then returns 404.
Route::prefix('setup')->name('setup.')->controller(SetupController::class)->group(function (): void {
    Route::get('/', 'welcome')->name('welcome');
    Route::post('unlock', 'unlock')->middleware('throttle:setup')->name('unlock');
    Route::get('server', 'server')->name('server');
    Route::get('database', 'databaseForm')->name('database');
    Route::post('database', 'database')->name('database.save');
    Route::get('finish', 'finishPage')->name('finish');
    Route::post('finish', 'finish')->name('finish.save');
});
