<?php

declare(strict_types=1);

/*
| B12 benchmark corpus generator (PLAN §5.2, for P4-2).
|
| Generates N synthetic receipt pages (GD) plus a manifest.json with the
| merchant/amount/date of each page – the input the receipt-pack benchmark
| renders into a 150-page PDF. All content is random and fake; no real
| document is used (public-repo rules).
|
| Usage: php scripts/b12-corpus.php [--pages=150] [--out=DIR] [--seed=42]
|   --pages  number of receipt pages (default 150, the B12 size)
|   --out    target directory (default storage/app/benchmark-b12, git-ignored)
|   --seed   RNG seed for reproducible corpora (default 42)
|
| Needs the GD extension: docker compose exec app php scripts/b12-corpus.php
*/

$options = getopt('', ['pages::', 'out::', 'seed::']);
$pages = max(1, (int) ($options['pages'] ?? 150));
$seed = (int) ($options['seed'] ?? 42);
$out = (string) ($options['out'] ?? dirname(__DIR__).'/storage/app/benchmark-b12');

if (! extension_loaded('gd')) {
    fwrite(STDERR, "GD extension missing – run inside the app container.\n");
    exit(2);
}

if (! is_dir($out) && ! mkdir($out, 0777, true)) {
    fwrite(STDERR, "Cannot create {$out}.\n");
    exit(2);
}

mt_srand($seed);

$merchants = ['Bäcker Krümel', 'Tanke Nord', 'Supermarkt Grün', 'Café Bohne', 'Drogerie Seifig', 'Buchladen Eselsohr'];
$items = ['Brötchen', 'Milch 1L', 'Kaffee 500g', 'Benzin E10', 'Seife', 'Roman, TB', 'Käse', 'Äpfel 1kg', 'Briefmarken', 'Spülmittel'];

$manifest = [];
$totalBytes = 0;

for ($n = 1; $n <= $pages; $n++) {
    $merchant = $merchants[mt_rand(0, count($merchants) - 1)];
    $bought = [];
    $total = 0;
    foreach (array_rand($items, mt_rand(2, 6)) as $i) {
        $price = mt_rand(99, 2499);
        $bought[] = [$items[$i], $price];
        $total += $price;
    }
    $date = sprintf('2026-%02d-%02d', mt_rand(1, 12), mt_rand(1, 28));

    $img = imagecreatetruecolor(900, 1400);
    $paper = imagecolorallocate($img, 250, 248, 242);
    $ink = imagecolorallocate($img, 40, 40, 40);
    imagefilledrectangle($img, 0, 0, 899, 1399, $paper);

    $y = 90;
    imagestring($img, 5, 60, $y, $merchant, $ink);
    $y += 60;
    imagestring($img, 3, 60, $y, "Beleg {$n}/{$pages} – {$date}", $ink);
    $y += 60;
    foreach ($bought as [$name, $price]) {
        imagestring($img, 4, 60, $y, $name, $ink);
        $amount = number_format($price / 100, 2, ',', '.').' EUR';
        imagestring($img, 4, 700 - strlen($amount) * 8, $y, $amount, $ink);
        $y += 44;
    }
    $y += 20;
    imagestring($img, 5, 60, $y, 'SUMME '.number_format($total / 100, 2, ',', '.').' EUR', $ink);

    $file = sprintf('receipt-%03d.jpg', $n);
    imagejpeg($img, $out.'/'.$file, 85);
    imagedestroy($img);

    $bytes = filesize($out.'/'.$file);
    $totalBytes += $bytes === false ? 0 : $bytes;
    $manifest[] = ['file' => $file, 'merchant' => $merchant, 'amount_minor' => $total, 'currency' => 'EUR', 'doc_date' => $date];
}

file_put_contents($out.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");

printf("Wrote %d pages + manifest.json to %s (%.1f MB images).\n", $pages, $out, $totalBytes / 1024 / 1024);
