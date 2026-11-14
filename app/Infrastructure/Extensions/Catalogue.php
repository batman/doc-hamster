<?php

declare(strict_types=1);

namespace App\Infrastructure\Extensions;

/**
 * The fixed extension catalogue (point 173.3): the app installs only what is listed here, never an address from a
 * request or a config file. Every file is pinned by SHA-256 and size; changing a pin is a code change with review.
 *
 * Adding a data pack: one entry with kind DATA, a group, a version and its files (url, sha256, size). The group's
 * feature reads the installed files itself (OCR: LocalOcr::languages()). A later translation pack (e.g. the
 * Firefox/Bergamot models) is one more group, 'translate', with dir 'translate/<pair>' and meta ['from' => …, 'to' => …].
 */
final class Catalogue
{
    /** tessdata_fast 4.1.0 – the same files as the core's deu/eng (LocalOcr::TESSDATA); 171: hashes and sizes pinned 2026-11-14. */
    private const string TESSDATA_URL = 'https://github.com/tesseract-ocr/tessdata_fast/raw/4.1.0/';

    /** code => [sha256, size] (171, 173.4: the core ships only deu + eng). */
    private const array OCR_LANGUAGES = [
        'ell' => ['4fba8a0b461038d51f1c20d043d4f2ac38c4e778f1b90830847f7bd8fa3ba726', 1419514],
        'fra' => ['ced037562e8c80c13122dece28dd477d399af80911a28791a66a63ac1e3445ca', 1130365],
        'ita' => ['b8f89e1e785118dac4d51ae042c029a64edb5c3ee42ef73027a6d412748d8827', 2701314],
        'spa' => ['6f2e04d02774a18f01bed44b1111f2cd7f3ba7ac9dc4373cd3f898a40ea6b464', 2294433],
        'por' => ['c4932b937207a9514b7514d518b931a99938c02a28a5a5a553f8599ed58b7deb', 1982756],
        'nld' => ['ced0e5e046a84c908a6aa7accbef9a232c4a5d9a8276691b81c6ee64d02963f6', 6050296],
        'pol' => ['c4476cdbc0e33d898d32345122b7be1cbf85ace15f920f06c7714756e1ef79b2', 4765518],
        'ces' => ['934bcaf97ef3348413263331131c9fa7f55f30db333c711929c124fb635f7e1b', 3795684],
        'dan' => ['acb1fd074487a31d1294fcdfd7d7c673467ffd8aeacb2ccd61ebcbf04eb4e2fa', 2580059],
        'swe' => ['f7304988d41f833efebcc2d529df54b1903ecebbc3da1faabd19a0fddd4fe586', 4167034],
        'hun' => ['35067e7cfe102dcdc953f9a758fdfaa6296b17a1ee6d874ee780fa306430b9fb', 5296273],
        'ron' => ['9adfde6b51ba4b97efd10ea37c3070fd3fc2bad7815e81f5c3c198cd96216cc9', 2376323],
        'hrv' => ['9e515d9832ce259dbab550b1cc6b998f8b929faf2edacaaca981b05adb130571', 4103348],
        'tur' => ['7393381111e1152420fc4092cb44eef4237580d21b92bf30d7d221aad192c6b7', 4550554],
        'rus' => ['e16e5e036cce1d9ec2b00063cf8b54472625b9e14d893a169e2b0dedeb4df225', 3861738],
        'ukr' => ['d59e53e2bded32f4445f124b4b00240fcac7e8044c003ab822ccb94f0b3db59b', 3825102],
        'ara' => ['e3206d3dc87fd50c24a0fb9f01838615911d25168f4e64415244b67d2bb3e729', 1432056],
    ];

    /** @var array<string, Extension>|null */
    private static ?array $all = null;

    /** @return array<string, Extension> key => entry, in display order */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $all = [];
        foreach (self::OCR_LANGUAGES as $code => [$sha, $size]) {
            $all['ocr-'.$code] = new Extension('ocr-'.$code, Extension::DATA, 'ocr', '4.1.0', [
                ['name' => $code.'.traineddata', 'url' => self::TESSDATA_URL.$code.'.traineddata', 'sha256' => $sha, 'size' => $size],
            ], 'ocr/tessdata', ['lang' => $code]);
        }
        // 173.1(b): code modules – listed, not installable yet (no release to pin; BankConnector Q-11 still open).
        $all['revolut'] = new Extension('revolut', Extension::MODULE, 'bank', null, [], 'modules/revolut');

        return self::$all = $all;
    }

    public static function find(string $key): ?Extension
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<Extension> */
    public static function group(string $group): array
    {
        return array_values(array_filter(self::all(), static fn (Extension $e): bool => $e->group === $group));
    }
}
