<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Browser capture: photograph a receipt with the phone camera (live preview
 * plus a file fallback) and send it through the normal document upload.
 * The snapshot is redrawn on a canvas, so EXIF and GPS data never leave
 * the phone. Full offline/PWA capture stays the Lead's PHONE #37.
 */
final class CaptureController
{
    public function __invoke(): View
    {
        return view('capture.show');
    }
}
