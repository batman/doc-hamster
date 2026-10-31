<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Temporary device probe for the phone website (#37): shows on real phones
 * whether home screen, camera, QR scan and a stored key work. Holds no data
 * and sends nothing to the server. Goes away once the real pairing exists.
 */
final class PhoneCheckController
{
    public function show(): View
    {
        return view('phone-check.show');
    }

    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => __('phone_check.title'),
            'short_name' => config('app.name'),
            'start_url' => route('phone-check', absolute: false),
            'scope' => '/',
            'display' => 'standalone',
            'icons' => [['src' => asset('phone-check-icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml']],
        ], options: JSON_UNESCAPED_SLASHES)->header('Content-Type', 'application/manifest+json');
    }

    public function qr(): View
    {
        return view('phone-check.qr', [
            'pageQr' => $this->qrImage(route('phone-check')),
            'testQr' => $this->qrImage('dochamster-test:'.Str::random(16)),
        ]);
    }

    private function qrImage(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(280), new SvgImageBackEnd)))->writeString($text);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
