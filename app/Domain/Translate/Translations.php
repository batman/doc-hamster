<?php

declare(strict_types=1);

namespace App\Domain\Translate;

use App\Infrastructure\Extensions\Extension;
use App\Infrastructure\Extensions\Extensions;

/**
 * Translation of the recognised text (point 172), run in the browser with Mozilla's Bergamot models: which language
 * pairs are installed (Einstellungen → Erweiterungen, group "translate"), how to get from the source language to a
 * target (directly or over the English pivot, 172.4: el→de = el→en + en→de) and which language a text is in.
 */
final class Translations
{
    public const string PIVOT = 'en';

    /** 172.2: the languages offered to translate into, in menu order. */
    public const array TARGETS = ['de', 'en'];

    /** Languages a text can be in (the sources of the catalogue's pairs). */
    public const array LANGUAGES = ['de', 'en', 'el'];

    /** Tesseract language codes (documents.ocr_langs) → ISO 639-1. */
    private const array OCR_CODES = ['deu' => 'de', 'eng' => 'en', 'ell' => 'el'];

    private const array MARKERS = [
        'de' => ['der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'für', 'von', 'zu', 'den', 'dem', 'ein', 'eine', 'auf', 'bitte', 'betrag', 'rechnung', 'summe', 'gesamt'],
        'en' => ['the', 'and', 'is', 'not', 'with', 'for', 'of', 'to', 'a', 'an', 'on', 'please', 'amount', 'invoice', 'total', 'thank', 'you', 'your'],
    ];

    public function __construct(private readonly Extensions $extensions) {}

    /**
     * The installed and switched-on language pairs.
     *
     * @return array<string, Extension> "from-to" => pair
     */
    public function pairs(): array
    {
        $out = [];
        foreach ($this->extensions->enabled('translate') as $ext) {
            if ($ext->files !== [] && isset($ext->meta['from'], $ext->meta['to'])) {
                $out[$ext->meta['from'].'-'.$ext->meta['to']] = $ext;
            }
        }

        return $out;
    }

    /**
     * The pairs to run one after the other to translate from → to: the direct pair, else over English.
     *
     * @param  array<string, mixed>  $pairs  "from-to" => anything
     * @return list<string>|null "from-to" keys, null = not possible with these pairs
     */
    public static function route(string $from, string $to, array $pairs): ?array
    {
        if ($from === $to) {
            return null;
        }
        if (isset($pairs[$from.'-'.$to])) {
            return [$from.'-'.$to];
        }
        $first = $from.'-'.self::PIVOT;
        $second = self::PIVOT.'-'.$to;
        if ($from !== self::PIVOT && $to !== self::PIVOT && isset($pairs[$first], $pairs[$second])) {
            return [$first, $second];
        }

        return null;
    }

    /**
     * The targets offered for a text in $from with the installed pairs.
     *
     * @return array<string, list<Extension>> target => the pairs to run
     */
    public function targets(string $from): array
    {
        $pairs = $this->pairs();
        $out = [];
        foreach (self::TARGETS as $to) {
            $route = self::route($from, $to, $pairs);
            if ($route !== null) {
                $out[$to] = array_map(static fn (string $key): Extension => $pairs[$key], $route);
            }
        }

        return $out;
    }

    /**
     * The language of a recognised text (172.2): mostly Greek letters → el; else the document's own OCR language
     * (A345) when it is German or English; else German or English by frequent short words.
     */
    public static function source(?string $ocrLangs, string $text): string
    {
        $letters = (int) preg_match_all('/\pL/u', $text);
        $greek = (int) preg_match_all('/\p{Greek}/u', $text);
        if ($letters > 0 && $greek / $letters >= 0.3) {
            return 'el';
        }
        $codes = array_values(array_filter(array_map(static fn (string $c): ?string => self::OCR_CODES[$c] ?? null, explode('+', (string) $ocrLangs)),
            static fn (?string $c): bool => $c !== null && $c !== 'el'));
        if (count($codes) === 1) {
            return $codes[0];
        }
        $words = array_count_values(preg_split('/[^\pL]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $score = static fn (string $lang): int => array_sum(array_map(static fn (string $w): int => $words[$w] ?? 0, self::MARKERS[$lang]));

        return $score('en') > $score('de') ? 'en' : 'de';
    }
}
