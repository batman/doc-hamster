<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack\Pdf;

use RuntimeException;

/**
 * A small PDF writer for the receipt pack (point 211.6; the app has no PDF library, TextPdf is the same idea for
 * plain text). Writes straight into a file, so a pack with hundreds of photos never sits in memory: every picture
 * (JPEG, DCTDecode) and every page goes out the moment it is added; fonts, page tree and the cross-reference table
 * follow at the end. Fonts: Helvetica and Helvetica-Bold (WinAnsi) and ZapfDingbats for ✓/✗.
 */
final class PdfWriter
{
    public const float WIDTH = 595.0;

    public const float HEIGHT = 842.0;

    /** Objects 1–5 are fixed: catalog, page tree, three fonts. */
    private int $nextId = 6;

    /** @var array<int, int> object id => byte offset */
    private array $offsets = [];

    /** @var list<int> */
    private array $kids = [];

    private int $written = 0;

    private bool $fonts = false;

    /** @var resource */
    private $file;

    public function __construct(private readonly string $path)
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Belegpaket: Datei lässt sich nicht schreiben');
        }
        $this->file = $handle;
        $this->put("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
    }

    /**
     * Adds a JPEG picture once; the canvas draws it by the returned name.
     *
     * @return array{name: string, width: int, height: int}
     */
    public function jpeg(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
            throw new RuntimeException('Belegpaket: kein JPEG');
        }
        $space = match ($info['channels'] ?? 3) {
            1 => '/DeviceGray',
            4 => '/DeviceCMYK /Decode [1 0 1 0 1 0 1 0]',
            default => '/DeviceRGB',
        };
        $id = $this->nextId++;
        $this->object($id, '<< /Type /XObject /Subtype /Image /Width '.$info[0].' /Height '.$info[1].' /ColorSpace '.$space
            .' /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($bytes)." >>\nstream\n".$bytes."\nendstream");

        return ['name' => 'Im'.$id, 'width' => $info[0], 'height' => $info[1]];
    }

    public function page(PdfCanvas $canvas): void
    {
        $stream = $canvas->stream();
        $contentId = $this->nextId++;
        $this->object($contentId, '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream");
        $xobjects = '';
        foreach ($canvas->images() as $name) {
            $xobjects .= '/'.$name.' '.substr($name, 2).' 0 R ';
        }
        $pageId = $this->nextId++;
        $this->fonts = true;
        $this->object($pageId, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>'
            .($xobjects !== '' ? ' /XObject << '.$xobjects.'>>' : '').' >> /Contents '.$contentId.' 0 R >>');
        $this->kids[] = $pageId;
    }

    /**
     * Point 236: a page of a rebuilt scan – its own boxes and rotation, the drawing taken over from the original (it
     * places the picture by a matrix, so a smaller picture lands on the same spot) and one picture under its old name.
     *
     * @param  array{name: string, width: int, height: int}  $image  from jpeg()
     * @param  array{float, float, float, float}  $mediaBox
     * @param  array{float, float, float, float}|null  $cropBox
     */
    public function scanPage(array $image, string $imageName, string $content, array $mediaBox, ?array $cropBox, int $rotate): void
    {
        $contentId = $this->nextId++;
        $this->object($contentId, '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream");
        $box = static fn (array $b): string => '['.implode(' ', array_map(static fn (float $n): string => rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.'), $b)).']';
        $pageId = $this->nextId++;
        $this->object($pageId, '<< /Type /Page /Parent 2 0 R /MediaBox '.$box($mediaBox).($cropBox !== null ? ' /CropBox '.$box($cropBox) : '')
            .($rotate !== 0 ? ' /Rotate '.$rotate : '').' /Resources << /XObject << /'.$imageName.' '.substr($image['name'], 2).' 0 R >> >> /Contents '.$contentId.' 0 R >>');
        $this->kids[] = $pageId;
    }

    public function pageCount(): int
    {
        return count($this->kids);
    }

    /** Fonts, page tree, catalog and the cross-reference table; returns the file size. */
    public function finish(): int
    {
        // A rebuilt scan (point 236) has no text: no fonts, their numbers stay free.
        if ($this->fonts) {
            $this->object(3, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
            $this->object(4, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
            $this->object(5, '<< /Type /Font /Subtype /Type1 /BaseFont /ZapfDingbats >>');
        }
        $kids = implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $this->kids));
        $this->object(2, '<< /Type /Pages /Kids ['.$kids.'] /Count '.count($this->kids).' >>');
        $this->object(1, '<< /Type /Catalog /Pages 2 0 R >>');

        $size = $this->nextId;
        $xref = $this->written;
        $table = "xref\n0 ".$size."\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $table .= isset($this->offsets[$id]) ? sprintf("%010d 00000 n \n", $this->offsets[$id]) : "0000000000 65535 f \n";
        }
        $this->put($table.'trailer << /Size '.$size." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n");
        fclose($this->file);

        return (int) filesize($this->path);
    }

    private function object(int $id, string $body): void
    {
        $this->offsets[$id] = $this->written;
        $this->put($id." 0 obj\n".$body."\nendobj\n");
    }

    private function put(string $bytes): void
    {
        fwrite($this->file, $bytes);
        $this->written += strlen($bytes);
    }
}
