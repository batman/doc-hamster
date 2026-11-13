<?php

declare(strict_types=1);

use App\Domain\Ocr\OcrWords;

/*
| Point 119: Tesseract TSV and poppler bbox output become the shared word JSON.
*/
it('reads words, lines and blocks from a Tesseract TSV', function (): void {
    $tsv = implode("\n", [
        "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext",
        "1\t1\t0\t0\t0\t0\t0\t0\t1000\t2000\t-1\t",
        "2\t1\t1\t0\t0\t0\t100\t100\t500\t40\t-1\t",
        "5\t1\t1\t1\t1\t1\t100\t100\t200\t40\t96.5\tRechnung",
        "5\t1\t1\t1\t1\t2\t320\t100\t100\t40\t88\tNr.",
        "5\t1\t1\t1\t2\t1\t100\t160\t150\t30\t71\tSumme",
        "5\t1\t2\t1\t1\t1\t600\t1800\t120\t30\t90\t12,00",
        "5\t1\t2\t1\t1\t2\t600\t1800\t120\t30\t-1\t ",
    ]);

    $page = OcrWords::pageFromTsv($tsv, 2);

    expect($page['n'])->toBe(2)->and($page['words'])->toHaveCount(4)
        ->and($page['words'][0])->toBe(['t' => 'Rechnung', 'x' => 0.1, 'y' => 0.05, 'w' => 0.2, 'h' => 0.02, 'c' => 97, 'l' => 0, 'b' => 0])
        ->and($page['words'][2]['l'])->toBe(1)
        ->and($page['words'][3]['b'])->toBe(1);

    $words = OcrWords::make('tesseract', [$page]);
    expect(OcrWords::plainText($words))->toBe("Rechnung Nr.\nSumme\n12,00");
    $layout = OcrWords::layoutText($page);
    expect($layout[0]['gaps'])->toBe([0, 1])->and($layout[2]['gaps'][0])->toBeGreaterThan(10);
    expect(OcrWords::pageFromTsv("level\tpage_num\n", 1))->toBeNull();
});

it('reads the text layer boxes of pdftotext -bbox-layout and applies corrections by position', function (): void {
    $xhtml = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body><doc>'
        .'<page width="200" height="100"><flow><block><line><word xMin="20" yMin="10" xMax="60" yMax="20">Total</word>'
        .'<word xMin="70" yMin="10" xMax="90" yMax="20">5,00&#160;€</word></line></block></flow></page></doc></body></html>';

    $pages = OcrWords::pagesFromBboxLayout($xhtml);
    expect($pages)->toHaveCount(1)->and($pages[0]['words'][0])->toMatchArray(['t' => 'Total', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.1, 'c' => 100]);

    $fixed = OcrWords::applyFixes(OcrWords::make('layer', $pages), [['p' => 1, 'x' => 0.3, 'y' => 0.05, 'w' => 0.2, 'h' => 0.2, 't' => '5,50 €', 'o' => '5,00 €']]);
    expect($fixed['pages'][0]['words'][1]['t'])->toBe('5,50 €')->and($fixed['pages'][0]['words'][0]['t'])->toBe('Total');
    expect(OcrWords::pagesFromBboxLayout('Usage: pdftotext [options]'))->toBeNull();
});
