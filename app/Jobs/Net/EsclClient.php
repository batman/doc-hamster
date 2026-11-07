<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * Talks to a network scanner or printer over eSCL ("AirScan"), the open
 * protocol most scanners since about 2015 speak. Written from the public
 * specification; no vendor software and no vendor-private interface.
 *
 * Pages come back as JPEG so we can merge them into one PDF ourselves
 * (PdfBuilder). The address must come from ScannerAddress::normalise().
 *
 * Lives in App\Jobs\Net (D-020) like UpdateSource: it runs only when a person
 * clicks (settings, scan), synchronously, and calls only a device on the
 * private network. A connection error becomes a RuntimeException with the
 * translation key `scan.unreachable`.
 */
final class EsclClient
{
    /** Page size in 1/300 inch: A4. */
    private const A4 = ['width' => 2480, 'height' => 3507];

    private const MAX_PAGES = 100;

    public function __construct(private readonly string $base) {}

    /**
     * @return array{model: string, platen: bool, feeder: bool}
     */
    public function capabilities(): array
    {
        $response = $this->call(fn (): Response => $this->http()->get($this->base.'/eSCL/ScannerCapabilities'));
        if (! $response->successful()) {
            throw new RuntimeException('scan.not_a_scanner');
        }
        $xml = $this->xml($response->body());
        if ($xml->getName() !== 'ScannerCapabilities') {
            throw new RuntimeException('scan.not_a_scanner');
        }

        return [
            'model' => trim((string) ($xml->xpath("//*[local-name()='MakeAndModel']")[0] ?? '')),
            'platen' => $xml->xpath("//*[local-name()='Platen']") !== [],
            'feeder' => $xml->xpath("//*[local-name()='Adf']") !== [],
        ];
    }

    /**
     * Starts a scan and returns every page as JPEG bytes. A feeder job returns
     * all sheets of the stack; the glass returns one page.
     *
     * @param  'platen'|'feeder'  $source
     * @param  'color'|'gray'|'bw'  $color
     * @return list<string>
     */
    public function scan(string $source, string $color, int $dpi): array
    {
        $response = $this->call(fn (): Response => $this->http()->withBody($this->settings($source, $color, $dpi), 'text/xml')->post($this->base.'/eSCL/ScanJobs'));
        $location = $response->header('Location');
        if (! $response->successful() || $location === '') {
            throw new RuntimeException($response->status() === 503 ? 'scan.busy' : 'scan.job_refused');
        }
        $job = $this->jobUrl($location);

        try {
            $pages = [];
            while (count($pages) < self::MAX_PAGES) {
                $page = $this->nextPage($job);
                if ($page === null) {
                    break;
                }
                $pages[] = $page;
                if ($source === 'platen') {
                    break;
                }
            }
        } finally {
            try {
                $this->call(fn (): Response => $this->http()->delete($job));
            } catch (RuntimeException) {
                // The device drops the job by itself after a while.
            }
        }
        if ($pages === []) {
            throw new RuntimeException($source === 'feeder' ? 'scan.feeder_empty' : 'scan.no_page');
        }

        return $pages;
    }

    /** The job address from the Location header; only on the same scanner, whatever the header says. */
    private function jobUrl(string $location): string
    {
        $path = parse_url($location, PHP_URL_PATH);
        if (! is_string($path) || ! str_contains($path, '/ScanJobs/')) {
            throw new RuntimeException('scan.job_refused');
        }

        return $this->base.$path;
    }

    private function nextPage(string $job): ?string
    {
        for ($try = 0; $try < 90; $try++) {
            $response = $this->call(fn (): Response => $this->http()->get($job.'/NextDocument'));
            if ($response->status() === 503) {
                usleep(500_000);

                continue;
            }
            if ($response->status() === 404 || $response->status() === 409) {
                return null;
            }
            if (! $response->successful()) {
                throw new RuntimeException('scan.job_failed');
            }
            $body = $response->body();
            if (! str_starts_with($body, "\xFF\xD8")) {
                throw new RuntimeException('scan.job_failed');
            }

            return $body;
        }

        throw new RuntimeException('scan.timeout');
    }

    private function settings(string $source, string $color, int $dpi): string
    {
        $mode = ['color' => 'RGB24', 'gray' => 'Grayscale8', 'bw' => 'BlackAndWhite1'][$color] ?? 'RGB24';
        $input = $source === 'feeder' ? 'Feeder' : 'Platen';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<scan:ScanSettings xmlns:pwg="http://www.pwg.org/schemas/2010/12/sm" xmlns:scan="http://schemas.hp.com/imaging/escl/2011/05/03">'
            .'<pwg:Version>2.1</pwg:Version>'
            .'<pwg:ScanRegions><pwg:ScanRegion><pwg:ContentRegionUnits>escl:ThreeHundredthsOfInches</pwg:ContentRegionUnits>'
            .'<pwg:XOffset>0</pwg:XOffset><pwg:YOffset>0</pwg:YOffset><pwg:Width>'.self::A4['width'].'</pwg:Width><pwg:Height>'.self::A4['height'].'</pwg:Height>'
            .'</pwg:ScanRegion></pwg:ScanRegions>'
            .'<pwg:InputSource>'.$input.'</pwg:InputSource>'
            .'<scan:ColorMode>'.$mode.'</scan:ColorMode>'
            .'<scan:XResolution>'.$dpi.'</scan:XResolution><scan:YResolution>'.$dpi.'</scan:YResolution>'
            .'<pwg:DocumentFormat>image/jpeg</pwg:DocumentFormat>'
            .'</scan:ScanSettings>';
    }

    /**
     * @param  Closure(): Response  $request
     */
    private function call(Closure $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new RuntimeException('scan.unreachable');
        }
    }

    private function xml(string $body): SimpleXMLElement
    {
        $xml = @simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($xml === false) {
            throw new RuntimeException('scan.not_a_scanner');
        }

        return $xml;
    }

    /** Printers use self-signed certificates; the address is checked to be on the private network. */
    private function http(): PendingRequest
    {
        return Http::withoutVerifying()->withUserAgent('DocHamster')->connectTimeout(5)->timeout(90)->withoutRedirecting();
    }
}
