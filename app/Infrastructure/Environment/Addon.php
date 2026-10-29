<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

/**
 * Optional features that are offered only where the server has the tools
 * (D-010). The core flow never depends on an add-on. Tool names follow
 * D-010/D-019; the image toolchain is refined with D-005.
 */
enum Addon: string
{
    case Ocr = 'ocr';
    case SpeechToText = 'speech_to_text';
    case Heic = 'heic';
    case Vips = 'vips';

    /**
     * Executables that must be on the PATH.
     *
     * @return list<string>
     */
    public function executables(): array
    {
        return match ($this) {
            self::Ocr => ['tesseract', 'ocrmypdf'],
            self::SpeechToText => ['whisper-cli', 'ffmpeg'],
            self::Heic => ['heif-convert'],
            self::Vips => [],
        };
    }

    /**
     * PHP extensions that must be loaded.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Vips => ['vips'],
            default => [],
        };
    }

    /** External programs are started via proc_open (argument arrays, PLAN §11.2). */
    public function needsProcOpen(): bool
    {
        return $this->executables() !== [];
    }
}
