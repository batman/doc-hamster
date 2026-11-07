<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * DocHamster as an app on the phone's home screen (PHONE, issue #37): the web
 * app manifest (icon, name, full screen) and the page shown when the phone
 * has no connection. No native app (D-019 spirit: nothing from an app store).
 */
final class AppShellController
{
    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'description' => __('app.shell.description'),
            'id' => route('home', absolute: false),
            'start_url' => route('home', absolute: false),
            'scope' => url('/').'/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#30302e',
            'theme_color' => '#30302e',
            'lang' => app()->getLocale(),
            'icons' => [
                ['src' => asset('img/app-icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('img/app-icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => asset('img/app-icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => __('documents.title'), 'url' => route('documents.index', absolute: false)],
            ],
        ], options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /** Kept by the service worker; shown when a page cannot be loaded. Photos taken here wait on the phone. */
    public function offline(): View
    {
        return view('app.offline');
    }
}
