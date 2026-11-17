<?php

declare(strict_types=1);

use App\Domain\Log\LogEntry;
use App\Http\CardFolds;
use App\Http\Controllers\Api\BankApiController;
use App\Http\Controllers\Api\DocumentApiController;
use App\Http\Controllers\Assistant\McpController;
use App\Http\Controllers\Assistant\OAuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PrinterApiController;
use App\Http\Controllers\UploadApiController;
use App\Http\MenuViews;
use App\Http\Middleware\AuthenticateAccessKey;
use App\Http\Middleware\ForgetBadgesOnChange;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Http\Middleware\RequireSameOriginApi;
use App\Http\Middleware\ResolveCurrentOrg;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ServerTiming;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\WindowEtag;
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
            // Point 164.2: the empty route – what the server needs before any app code (baseline, browser ping).
            Route::get('/ping', static fn () => response('', 204, ['Cache-Control' => 'no-store']))->name('ping');

            // The printer "Hamster" on a computer: no session, its own token (PrinterApiController).
            Route::prefix('api/printer')->name('api.printer.')->group(function (): void {
                Route::post('pair', [PrinterApiController::class, 'pair'])->middleware('throttle:pairing')->name('pair');
                Route::post('upload', [PrinterApiController::class, 'upload'])->middleware('throttle:120,1')->name('upload');
            });

            // Points 152.2, A330: an iPhone shortcut or a program sends documents with its device key.
            Route::post('api/upload', UploadApiController::class)->middleware('throttle:120,1')->name('api.upload');
            // Point 201.5: "Verbindung testen" – the same key check, nothing is stored.
            Route::get('api/upload', [UploadApiController::class, 'check'])->middleware('throttle:30,1')->name('api.upload.check');

            // D-040: the interface for programs and assistants – a person's access key (Mein Konto → Zugangsschlüssel),
            // no session. Reading bookings and documents; linking only with a "Lesen + Verknüpfen" key. Never deletes.
            Route::prefix('api/v1')->name('api.v1.')->middleware(['throttle:api-key', AuthenticateAccessKey::ALIAS])->group(function (): void {
                Route::get('/', [BankApiController::class, 'me'])->name('me');
                Route::get('accounts', [BankApiController::class, 'accounts'])->name('accounts');
                Route::get('transactions', [BankApiController::class, 'transactions'])->name('transactions');
                Route::get('transactions/{id}', [BankApiController::class, 'transaction'])->name('transaction');
                Route::post('transactions/{id}/link', [BankApiController::class, 'link'])->name('link');
                Route::post('transactions/{id}/unlink', [BankApiController::class, 'unlink'])->name('unlink');
                Route::post('transactions/{id}/confirm', [BankApiController::class, 'confirm'])->name('confirm');
                Route::post('transactions/{id}/reject', [BankApiController::class, 'reject'])->name('reject');
                Route::get('folders', [DocumentApiController::class, 'folders'])->name('folders');
                Route::get('documents', [DocumentApiController::class, 'documents'])->name('documents');
                Route::get('documents/{id}', [DocumentApiController::class, 'document'])->name('document');
            });

            // D-040: assistants connect by clicks – where to sign in (RFC 9728, RFC 8414), registering (RFC 7591), the
            // tokens, and the MCP address itself. All of it "not here" while no organisation has the door open.
            Route::get('.well-known/oauth-protected-resource/{path?}', [OAuthController::class, 'resource'])->where('path', '.*')->name('oauth.resource');
            Route::get('.well-known/oauth-authorization-server/{path?}', [OAuthController::class, 'server'])->where('path', '.*')->name('oauth.server');
            Route::post('oauth/register', [OAuthController::class, 'register'])->middleware('throttle:10,1')->name('oauth.register');
            Route::post('oauth/token', [OAuthController::class, 'token'])->middleware('throttle:60,1')->name('oauth.token');
            Route::post('mcp', [McpController::class, 'handle'])->middleware(['throttle:api-key', AuthenticateAccessKey::ALIAS.':mcp'])->name('mcp');
            Route::match(['get', 'delete'], 'mcp', [McpController::class, 'other']);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Point 164.1: first in, last out – the Server-Timing covers every other middleware.
        $middleware->prepend(ServerTiming::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(prepend: [RedirectToSetup::class], append: [SetLocale::class, WindowEtag::class, ForgetBadgesOnChange::class]);
        // Point 125, A331: the folded cards (CardFolds) and menu window displays (MenuViews) are written by JavaScript and hold nothing secret.
        $middleware->encryptCookies(except: [CardFolds::COOKIE, MenuViews::COOKIE]);
        // Point 151: the share sheet posts to /share without a token when no service worker caught it;
        // the session cookie is SameSite=Lax, so another site's POST arrives logged out anyway.
        $middleware->validateCsrfTokens(except: ['share']);
        $middleware->alias([
            'org' => ResolveCurrentOrg::class,
            'api.same-origin' => RequireSameOriginApi::class,
            AuthenticateAccessKey::ALIAS => AuthenticateAccessKey::class,
            RequireRecentConfirmation::ALIAS => RequireRecentConfirmation::class,
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
