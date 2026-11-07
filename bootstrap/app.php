<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\PrinterApiController;
use App\Http\Controllers\StationApiController;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\RequireSameOriginApi;
use App\Http\Middleware\ResolveCurrentOrg;
use App\Http\Middleware\RestoreDeviceLogin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Outside the `web` group: monitoring polls must not start sessions.
        then: function (): void {
            Route::get('/health', HealthController::class)->name('health');

            // The printer "Hamster" on a computer: no session, its own token (PrinterApiController).
            Route::prefix('api/printer')->name('api.printer.')->group(function (): void {
                Route::post('pair', [PrinterApiController::class, 'pair'])->middleware('throttle:pairing')->name('pair');
                Route::post('upload', [PrinterApiController::class, 'upload'])->middleware('throttle:120,1')->name('upload');
            });

            // The Scan Station next to a printer: connects out, its own token (StationApiController).
            Route::prefix('api/station')->name('api.station.')->group(function (): void {
                Route::post('pair', [StationApiController::class, 'pair'])->middleware('throttle:pairing')->name('pair');
                Route::post('poll', [StationApiController::class, 'poll'])->middleware('throttle:60,1')->name('poll');
                Route::post('requests/{id}/pages', [StationApiController::class, 'page'])->middleware('throttle:300,1')->name('page');
                Route::get('requests/{id}/file', [StationApiController::class, 'file'])->middleware('throttle:60,1')->name('file');
                Route::post('requests/{id}/finish', [StationApiController::class, 'finish'])->middleware('throttle:60,1')->name('finish');
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(prepend: [RedirectToSetup::class], append: [SetLocale::class, RestoreDeviceLogin::class]);
        // A connected phone must be signed in again before `auth` looks (Laravel sorts `auth` early).
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: RestoreDeviceLogin::class);
        $middleware->alias([
            'org' => ResolveCurrentOrg::class,
            'api.same-origin' => RequireSameOriginApi::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
