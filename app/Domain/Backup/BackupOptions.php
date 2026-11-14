<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use Carbon\CarbonImmutable;

/**
 * What a backup holds (point 206, D-023): everything or a period (by document date, bookings by booking date),
 * originals only or also the derived files (thumbnails), and which parts – all ticked by default.
 */
final readonly class BackupOptions
{
    public const string DOCUMENTS = 'documents';

    public const string BANK = 'bank';

    public const string NOTES = 'notes';

    public const string SETTINGS = 'settings';

    public const array SECTIONS = [self::DOCUMENTS, self::BANK, self::NOTES, self::SETTINGS];

    /**
     * @param  list<string>  $sections
     */
    public function __construct(
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public bool $derived = false,
        public array $sections = self::SECTIONS,
    ) {}

    /** Everything, all parts: only such a backup can be restored (a period backup is an archive). */
    public function isFull(): bool
    {
        return $this->from === null && $this->to === null && array_values(array_intersect(self::SECTIONS, $this->sections)) === self::SECTIONS;
    }

    public function has(string $section): bool
    {
        return in_array($section, $this->sections, true);
    }

    /**
     * From the form (or the stored row): `range` = '' (Alles) or 'period' with `from` / `to` (Y-m-d), `derived`,
     * `parts[]`. Unknown parts are dropped; an empty choice keeps nothing but the organisation itself.
     *
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input): self
    {
        $date = static function (mixed $value): ?CarbonImmutable {
            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null : null;
        };
        $period = ($input['range'] ?? '') === 'period';
        $parts = $input['parts'] ?? self::SECTIONS;
        $sections = array_values(array_filter(self::SECTIONS, static fn (string $s): bool => is_array($parts) && in_array($s, $parts, true)));

        return new self(
            $period ? $date($input['from'] ?? null) : null,
            $period ? $date($input['to'] ?? null) : null,
            filter_var($input['derived'] ?? false, FILTER_VALIDATE_BOOLEAN),
            $sections,
        );
    }

    /**
     * @return array{range: string, from: ?string, to: ?string, derived: bool, parts: list<string>}
     */
    public function toArray(): array
    {
        return [
            'range' => $this->from !== null || $this->to !== null ? 'period' : '',
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'derived' => $this->derived,
            'parts' => $this->sections,
        ];
    }
}
