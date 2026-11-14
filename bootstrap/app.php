<?php

declare(strict_types=1);

use App\Domain\Log\LogEntry;
use App\Http\CardFolds;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PrinterApiController;
use App\Http\Controllers\UploadApiController;
use App\Http\MenuViews;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\RequireSameOriginApi;
use App\Http\Middleware\ResolveCurrentOrg;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
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

            // Points 152.2, A330: an iPhone shortcut or a program sends documents with its device key.
            Route::post('api/upload', UploadApiController::class)->middleware('throttle:120,1')->name('api.upload');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(prepend: [RedirectToSetup::class], append: [SetLocale::class]);
        // Point 125, A331: the folded cards (CardFolds) and menu window displays (MenuViews) are written by JavaScript and hold nothing secret.
        $middleware->encryptCookies(except: [CardFolds::COOKIE, MenuViews::COOKIE]);
        // Point 151: the share sheet posts to /share without a token when no service worker caught it;
        // the session cookie is SameSite=Lax, so another site's POST arrives logged out anyway.
        $middleware->validateCsrfTokens(except: ['share']);
        $middleware->alias([
            'org' => ResolveCurrentOrg::class,
            'api.same-origin' => RequireSameOriginApi::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Point 115: every reported server error also lands in Einstellungen → Log ("Allgemein", level error).
        $exceptions->report(static function (Throwable $e): void {
            LogEntry::write('general', 'error', class_basename($e).': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')', 'server.error');
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
