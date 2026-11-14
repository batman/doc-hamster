<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

use Carbon\CarbonImmutable;

/**
 * The period of a receipt pack and its short name for the file (A379): a whole quarter "2026-Q3", a whole month
 * "2026-07", a whole year "2026", anything else "2026-07-01_2026-09-30".
 */
final readonly class PackPeriod
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(CarbonImmutable $from, CarbonImmutable $to)
    {
        $this->from = $from->startOfDay();
        $this->to = $to->startOfDay();
    }

    public static function of(string $from, string $to): self
    {
        return new self(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
    }

    /** The quick choices of the form: last month, last quarter, last year – seen from $today. */
    public static function preset(string $name, ?CarbonImmutable $today = null): ?self
    {
        $today ??= CarbonImmutable::today();

        return match ($name) {
            'month' => new self($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()),
            'quarter' => new self($today->subQuarterNoOverflow()->startOfQuarter(), $today->subQuarterNoOverflow()->endOfQuarter()),
            'year' => new self($today->subYearNoOverflow()->startOfYear(), $today->subYearNoOverflow()->endOfYear()),
            default => null,
        };
    }

    public function label(): string
    {
        $from = $this->from;
        $to = $this->to;
        if ($from->isSameDay($from->startOfYear()) && $to->isSameDay($from->endOfYear())) {
            return $from->format('Y');
        }
        if ($from->isSameDay($from->startOfQuarter()) && $to->isSameDay($from->endOfQuarter())) {
            return $from->format('Y').'-Q'.$from->quarter;
        }
        if ($from->isSameDay($from->startOfMonth()) && $to->isSameDay($from->endOfMonth())) {
            return $from->format('Y-m');
        }

        return $from->format('Y-m-d').'_'.$to->format('Y-m-d');
    }

    /** "01.07.2026 – 30.09.2026" for people. */
    public function human(): string
    {
        return $this->from->format('d.m.Y')." \u{2013} ".$this->to->format('d.m.Y');
    }

    public static function fileName(string $label, int $version): string
    {
        return 'Belegpaket_'.$label.'_v'.$version.'.pdf';
    }
}
