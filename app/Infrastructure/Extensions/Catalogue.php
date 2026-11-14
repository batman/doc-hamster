<?php

declare(strict_types=1);

namespace App\Infrastructure\Extensions;

/**
 * The fixed extension catalogue (point 173.3): the app installs only what is listed here, never an address from a
 * request or a config file. Every file is pinned by SHA-256 and size; changing a pin is a code change with review.
 *
 * Adding a data pack: one entry with kind DATA, a group, a version and its files (url, sha256, size). The group's
 * feature reads the installed files itself (OCR: LocalOcr::languages(); translation: Translations::pairs()).
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

    /**
     * 172: Mozilla's Firefox Translations (Bergamot) files, only from Firefox Remote Settings (bucket main, collections
     * translations-models / translations-wasm; attachments base URL from /v1/ capabilities.attachments.base_url).
     * The set Firefox ESR 140 runs: bergamot-translator wasm 2.0 (with the JS glue in public/vendor/bergamot, same
     * Firefox revision) and language models of major version 1–2. Licence MPL-2.0. Pinned 2026-11-14.
     */
    private const string MOZILLA_URL = 'https://firefox-settings-attachments.cdn.mozilla.net/main-workspace/';

    /** pair => [version, [role => [file name, attachment location, sha256, size]]] */
    private const array TRANSLATE_PAIRS = [
        'el-en' => ['1.1', [
            'model' => ['model.elen.intgemm.alphas.bin', 'translations-models/027aaa60-3ef8-437d-a9d2-fb6b801e72a4.bin', '0a43c3716156061494d995d0c88113e789f8d919e0c88a15bc1ad360e0482a8c', 17141051],
            'lex' => ['lex.50.50.elen.s2t.bin', 'translations-models/2a3b9057-540f-480b-934f-60e0833f0b87.bin', '2c969b069b3d230feddd17db2d74eb658a9b33b960d2d40adbca5496c9784e85', 4295860],
            'vocab' => ['vocab.elen.spm', 'translations-models/423d004f-9f48-46a0-b320-1d8456cbdc63.spm', 'e1a1298cc2cf3968f3d7cc3b36bc463a4e1bf154272e8fb2f15142b54edc4349', 894960],
        ]],
        'en-el' => ['1.0', [
            'model' => ['model.enel.intgemm.alphas.bin', 'translations-models/26dead91-e46c-442c-85d0-a6fe13fad55a.bin', '724b358d399c6b23444de36d76e9e2630c7024c29d9e617323b820a11631535a', 17141051],
            'lex' => ['lex.50.50.enel.s2t.bin', 'translations-models/73568803-85e3-423a-8fb8-7f755a6ffed2.bin', 'c2acd9241eb8a1e5b13a43efa5723628839d6e3b3dc7b93e2f20990a6ab81e24', 2856724],
            'vocab' => ['vocab.enel.spm', 'translations-models/79a76f88-a4c4-4b1d-b726-15c6a60bebcc.spm', '0ffc5569d7f400f03539c5fc3e28ff35f1d0381eba0ec866c44aef0c26f3ce5e', 896168],
        ]],
        'en-de' => ['2.1', [
            'model' => ['model.ende.intgemm.alphas.bin', 'translations-models/23db71e7-b6d9-45eb-a47d-0290d7d8ef63.bin', '8df29d9494d19f47fd5d97c6a73474c6f657e9f81c1a607c431d02befdf3810f', 31561787],
            'lex' => ['lex.50.50.ende.s2t.bin', 'translations-models/bc072b1a-7749-43f7-9fe0-34a6dff10c4a.bin', '7ed39f1cffbd68a27ddf05bbfe068de2060f1d7e69f1a20e27ae923551dd7393', 4347672],
            'vocab' => ['vocab.ende.spm', 'translations-models/261225ea-5a52-455b-981c-7d09c6e6da3c.spm', '69f730becafa48e3bb2c244eab66456877c08959a02f2bd5519b5a3088b62f9c', 810073],
        ]],
        'de-en' => ['2.0', [
            'model' => ['model.deen.intgemm.alphas.bin', 'translations-models/f44b1b1b-9df6-4ece-971e-0e5ce96fae54.bin', '3e6f7c2c2425d10824797270b382bee718ff34af2cab9308841c82ca46dc6f20', 31561787],
            'lex' => ['lex.50.50.deen.s2t.bin', 'translations-models/d0e4efcb-6145-43db-a69e-568904cc2925.bin', '113b98460468360cca68c042e1cddf49c4e1931cbb975ed04349c9a3bd607010', 4945796],
            'vocab' => ['vocab.deen.spm', 'translations-models/8ad4d93e-21e6-4862-81d5-c1c3a7d0767b.spm', '69f730becafa48e3bb2c244eab66456877c08959a02f2bd5519b5a3088b62f9c', 810073],
        ]],
    ];

    /** 172.1: the bergamot-translator wasm (Remote Settings translations-wasm "bergamot-translator" 2.0, MPL-2.0). */
    private const array TRANSLATE_ENGINE = ['2.0', 'bergamot-translator.wasm', 'translations-wasm/ec4aab08-4e7c-4a62-a7a5-ee6874a5d1a2.wasm', 'e67e2f8be1a3fc0d9b00adce8b641cdf767bf3de90f1b3ccf732c2d711304fa3', 4956176];

    /** 172.4: bundles over the English pivot (Mozilla has no direct el→de): bundle => the pairs it installs. */
    private const array TRANSLATE_BUNDLES = ['el-de' => ['el-en', 'en-de']];

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
        [$version, $name, $location, $sha, $size] = self::TRANSLATE_ENGINE;
        $all['translate-engine'] = new Extension('translate-engine', Extension::DATA, 'translate', $version, [
            ['name' => $name, 'url' => self::MOZILLA_URL.$location, 'sha256' => $sha, 'size' => $size],
        ], 'translate/engine', ['hidden' => '1']);
        foreach (self::TRANSLATE_BUNDLES as $pair => $steps) {
            [$from, $to] = explode('-', $pair);
            $all['translate-'.$pair] = new Extension('translate-'.$pair, Extension::DATA, 'translate', '1', [], 'translate/'.$pair,
                ['from' => $from, 'to' => $to, 'via' => 'en'], array_map(static fn (string $step): string => 'translate-'.$step, $steps));
        }
        foreach (self::TRANSLATE_PAIRS as $pair => [$version, $roles]) {
            [$from, $to] = explode('-', $pair);
            $files = [];
            $meta = ['from' => $from, 'to' => $to];
            foreach ($roles as $role => [$name, $location, $sha, $size]) {
                $files[] = ['name' => $name, 'url' => self::MOZILLA_URL.$location, 'sha256' => $sha, 'size' => $size];
                $meta[$role] = $name;
            }
            $all['translate-'.$pair] = new Extension('translate-'.$pair, Extension::DATA, 'translate', $version, $files, 'translate/'.$pair, $meta, ['translate-engine']);
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
