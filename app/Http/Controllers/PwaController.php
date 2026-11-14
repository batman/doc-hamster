<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The web app manifest of the whole app (points 151, A328): installable on the home screen and, on
 * Android, a target of the share menu (POST /share, kept by public/sw.js until it is sent).
 */
final class PwaController
{
    public function manifest(): JsonResponse
    {
        $name = (string) config('app.name');

        return response()->json([
            'id' => route('home', absolute: false),
            'name' => $name,
            'short_name' => $name,
            'lang' => app()->getLocale(),
            'start_url' => route('home', absolute: false),
            'scope' => route('home', absolute: false),
            'display' => 'standalone',
            'background_color' => '#30302e',
            'theme_color' => '#30302e',
            'icons' => [
                ['src' => asset('img/app-icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('img/app-icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => asset('img/app-icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'share_target' => [
                'action' => route('share.store', absolute: false),
                'method' => 'POST',
                'enctype' => 'multipart/form-data',
                'params' => [
                    'title' => 'title',
                    'text' => 'text',
                    'url' => 'url',
                    'files' => [['name' => 'files', 'accept' => ['application/pdf', 'image/*', '*/*']]],
                ],
            ],
        ], options: JSON_UNESCAPED_SLASHES)->header('Content-Type', 'application/manifest+json');
    }
}
