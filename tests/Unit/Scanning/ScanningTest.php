<?php

declare(strict_types=1);

use App\Domain\Scanning\PdfBuilder;
use App\Domain\Scanning\ScannerAddress;

/*
| Issue #77: the scanner address is only ever a device on the private network,
| and scanned pages become one PDF without any library.
*/

function jpegBytes(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

it('accepts addresses on the private network and writes them in one form', function (string $input, string $expected): void {
    expect(ScannerAddress::normalise($input))->toBe($expected);
})->with([
    ['192.168.1.20', 'http://192.168.1.20'],
    [' http://10.0.0.5/ ', 'http://10.0.0.5'],
    ['https://172.16.4.9:8443', 'https://172.16.4.9:8443'],
    ['HTTP://10.1.2.3:8080', 'http://10.1.2.3:8080'],
    ['[fd12:3456::1]', 'http://[fd12:3456::1]'],
]);

it('refuses everything that is not a plain private address', function (string $input): void {
    expect(fn () => ScannerAddress::normalise($input))->toThrow(InvalidArgumentException::class);
})->with([
    'loopback' => '127.0.0.1',
    'localhost name' => 'localhost',
    'cloud metadata' => '169.254.169.254',
    'public address' => '8.8.8.8',
    'public IPv6' => '2001:db8::1',
    'IPv6 loopback' => '[::1]',
    'credentials' => 'http://user:pw@192.168.1.20',
    'a path' => 'http://192.168.1.20/admin',
    'a query' => 'http://192.168.1.20/?x=1',
    'other scheme' => 'ftp://192.168.1.20',
    'port zero' => '192.168.1.20:0',
    'empty' => '',
    'garbage' => 'not an address at all',
    'public RFC range edge' => '172.32.0.1',
    'just below 10/8' => '9.255.255.255',
]);

it('builds one PDF with a page per JPEG, sized after the resolution', function (): void {
    $pdf = PdfBuilder::fromJpegs([jpegBytes(1654, 2339), jpegBytes(200, 100)], 200);

    expect($pdf)->toStartWith('%PDF-1.4')
        ->and($pdf)->toContain('/Count 2')
        ->and($pdf)->toContain('/MediaBox [0 0 595.44 842.04]')
        ->and($pdf)->toContain('/MediaBox [0 0 72 36]')
        ->and($pdf)->toContain('/Filter /DCTDecode')
        ->and($pdf)->toEndWith("%%EOF\n");
});

it('writes a cross-reference table whose offsets point at the objects', function (): void {
    $pdf = PdfBuilder::fromJpegs([jpegBytes(30, 20), jpegBytes(30, 20), jpegBytes(30, 20)], 150);

    preg_match('/startxref\n(\d+)\n/', $pdf, $start);
    expect(substr($pdf, (int) $start[1], 4))->toBe('xref');

    preg_match_all('/(\d{10}) 00000 n \n/', $pdf, $offsets);
    expect($offsets[1])->toHaveCount(2 + 3 * 3);
    foreach ($offsets[1] as $number => $offset) {
        expect(substr($pdf, (int) $offset, strlen((string) ($number + 1)) + 6))->toBe(($number + 1).' 0 obj');
    }
});

it('reads size and colour components from the JPEG header and refuses other data', function (): void {
    expect(PdfBuilder::inspect(jpegBytes(33, 17)))->toBe([33, 17, 3]);
    expect(fn () => PdfBuilder::inspect('GIF89a'))->toThrow(InvalidArgumentException::class);
    expect(fn () => PdfBuilder::inspect("\xFF\xD8\xFF\xD9"))->toThrow(InvalidArgumentException::class);
    expect(fn () => PdfBuilder::fromJpegs([], 200))->toThrow(InvalidArgumentException::class);
    expect(fn () => PdfBuilder::fromJpegs([jpegBytes(5, 5)], 10))->toThrow(InvalidArgumentException::class);
});
