<?php

declare(strict_types=1);

namespace App\Jobs\Local\Ocr;

use Throwable;

/**
 * Point 238 (A418): which way a page lies, from Tesseract's orientation detection (`--psm 0`, needs
 * osd.traineddata). The system tesseract usually brings it (Debian's tesseract-ocr depends on tesseract-ocr-osd);
 * the app-owned Tesseract only when someone put osd.traineddata into its tessdata – the app downloads none. Without
 * it, or when Tesseract is unsure, the page stays as it is.
 */
final class Orientation
{
    /** Tesseract's "Orientation confidence" below this is a guess – the page is not turned. */
    public const float MIN_CONFIDENCE = 2.0;

    private function __construct(private readonly OcrTools $tools, private readonly bool $app) {}

    /** Null when no Tesseract here can detect the orientation. */
    public static function for(OcrTools $tools, bool $app): ?self
    {
        $ocr = $tools->ocr();
        if ($app && ! is_file($ocr->tessdataDir().'/osd.traineddata')) {
            return null;
        }

        return new self($tools, $app);
    }

    /** Degrees to turn the page clockwise so it stands upright (0, 90, 180, 270); 0 when unsure or on any error. */
    public function rotation(string $image, Deadline $deadline): int
    {
        if ($deadline->passed(60)) {
            return 0;
        }
        $ocr = $this->tools->ocr();
        try {
            $out = $this->tools->run($ocr->command([$image, 'stdout', '--psm', '0'], $this->app), $ocr->env($this->app), $deadline);
        } catch (Throwable) {
            return 0; // no osd.traineddata, too little text … – the page stays
        }

        return self::parse($out);
    }

    /**
     * Tesseract's OSD report: "Rotate: 90" is the clockwise turn that makes the page upright (tesseract baseapi
     * GetOSDText), "Orientation confidence" how sure it is.
     */
    public static function parse(string $out): int
    {
        if (preg_match('/^Rotate:\s*(\d+)/m', $out, $rotate) !== 1 || preg_match('/^Orientation confidence:\s*([\d.]+)/m', $out, $sure) !== 1) {
            return 0;
        }
        $angle = (int) $rotate[1];
        if ((float) $sure[1] < self::MIN_CONFIDENCE || ! in_array($angle, [90, 180, 270], true)) {
            return 0;
        }

        return $angle;
    }
}
