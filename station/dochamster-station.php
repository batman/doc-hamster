<?php

declare(strict_types=1);

/**
 * DocHamster Scan Station – MIT licence, part of DocHamster (issue #77).
 *
 * A small program for a computer that is always on in the home network, next
 * to a printer or scanner that can scan over the network (eSCL / AirScan).
 * It connects OUT to your DocHamster over HTTPS, so nothing at home has to be
 * opened in the router. When someone presses "Scannen" in DocHamster, this
 * program scans on the printer and sends the pages back.
 *
 * Needs only PHP 8.1+ with the curl and SimpleXML extensions (the official
 * php:8-cli Docker image has both). No other software, no vendor tools.
 *
 * Settings (environment variables):
 *   DOCHAMSTER_URL   address of your DocHamster, e.g. https://dochamster.example.com
 *   PRINTER_URL      address of the printer in the home network, e.g. 192.168.0.20
 *   PAIRING_CODE     the one-time code from DocHamster (only for the first start)
 *   STATION_NAME     optional name shown in DocHamster
 *   DATA_DIR         where the station keeps its key (default: ./data, in Docker /data)
 *
 * Options: --check (talk to the printer, then stop), --once (one round, then stop)
 */

// ---- settings ---------------------------------------------------------------

const POLL_SECONDS = 3;
const MAX_PAGES = 100;

function setting(string $name, ?string $default = null): ?string
{
    $value = getenv($name);

    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function say(string $message): void
{
    fwrite(STDOUT, '['.date('H:i:s').'] '.$message."\n");
}

function stop(string $message, int $code = 1): never
{
    fwrite(STDERR, $message."\n");
    exit($code);
}

// ---- the printer: private network only ---------------------------------------

/** Accepts only http(s) to a device on the private network; returns scheme://host[:port]. */
function printerBase(string $input): string
{
    $parts = parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $input) === 1 ? $input : 'http://'.$input);
    if ($parts === false || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || (($parts['path'] ?? '') !== '' && $parts['path'] !== '/') || isset($parts['query'])) {
        stop('PRINTER_URL is not a valid address.');
    }
    $host = trim(strtolower($parts['host']), '[]');
    $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);
    if (! isPrivate($ip)) {
        stop('PRINTER_URL must be a device in your home network (192.168.x.x, 10.x.x.x or 172.16.x.x).');
    }

    return strtolower($parts['scheme']).'://'.(str_contains($host, ':') ? '['.$host.']' : $host).(isset($parts['port']) ? ':'.$parts['port'] : '');
}

function isPrivate(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $long = ip2long($ip);

        return $long !== false && (($long & 0xFF000000) === 0x0A000000 || ($long & 0xFFF00000) === 0xAC100000 || ($long & 0xFFFF0000) === 0xC0A80000);
    }
    $packed = @inet_pton($ip);

    return $packed !== false && strlen($packed) === 16 && (ord($packed[0]) & 0xFE) === 0xFC;
}

// ---- HTTP ---------------------------------------------------------------------

/**
 * @param  list<string>  $headers
 * @return array{int, string, array<string, string>} status, body, lower-case response headers
 */
function http(string $method, string $url, ?string $body, array $headers, bool $verifyTls): array
{
    $curl = curl_init($url);
    $received = [];
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => $verifyTls,
        CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        CURLOPT_USERAGENT => 'DocHamster-Station',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) {
                $received[strtolower(trim($pair[0]))] = trim($pair[1]);
            }

            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $answer = curl_exec($curl);
    if ($answer === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('connection: '.$error);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return [$status, (string) $answer, $received];
}

// ---- the printer: eSCL ------------------------------------------------------------

/** @return array{model: string, feeder: bool} */
function printerInfo(string $printer): array
{
    try {
        [$status, $body] = http('GET', $printer.'/eSCL/ScannerCapabilities', null, [], false);
    } catch (RuntimeException) {
        throw new RuntimeException('scan.unreachable');
    }
    $xml = $status === 200 ? @simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET) : false;
    if ($xml === false || $xml->getName() !== 'ScannerCapabilities') {
        throw new RuntimeException('scan.not_a_scanner');
    }

    return [
        'model' => trim((string) ($xml->xpath("//*[local-name()='MakeAndModel']")[0] ?? '')),
        'feeder' => $xml->xpath("//*[local-name()='Adf']") !== [],
    ];
}

/**
 * Scans and returns the pages as JPEG bytes: the whole stack from the feeder, one page from the glass.
 *
 * @return list<string>
 */
function scan(string $printer, string $source, string $color, int $dpi): array
{
    $mode = ['color' => 'RGB24', 'gray' => 'Grayscale8', 'bw' => 'BlackAndWhite1'][$color] ?? 'RGB24';
    $settings = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<scan:ScanSettings xmlns:pwg="http://www.pwg.org/schemas/2010/12/sm" xmlns:scan="http://schemas.hp.com/imaging/escl/2011/05/03">'
        .'<pwg:Version>2.1</pwg:Version><pwg:ScanRegions><pwg:ScanRegion><pwg:ContentRegionUnits>escl:ThreeHundredthsOfInches</pwg:ContentRegionUnits>'
        .'<pwg:XOffset>0</pwg:XOffset><pwg:YOffset>0</pwg:YOffset><pwg:Width>2480</pwg:Width><pwg:Height>3507</pwg:Height></pwg:ScanRegion></pwg:ScanRegions>'
        .'<pwg:InputSource>'.($source === 'feeder' ? 'Feeder' : 'Platen').'</pwg:InputSource><scan:ColorMode>'.$mode.'</scan:ColorMode>'
        .'<scan:XResolution>'.$dpi.'</scan:XResolution><scan:YResolution>'.$dpi.'</scan:YResolution>'
        .'<pwg:DocumentFormat>image/jpeg</pwg:DocumentFormat></scan:ScanSettings>';

    try {
        [$status, , $headers] = http('POST', $printer.'/eSCL/ScanJobs', $settings, ['Content-Type: text/xml'], false);
        $path = parse_url($headers['location'] ?? '', PHP_URL_PATH);
        if ($status < 200 || $status > 299 || ! is_string($path) || ! str_contains($path, '/ScanJobs/')) {
            throw new RuntimeException($status === 503 ? 'scan.busy' : 'scan.job_refused');
        }
        $job = $printer.$path;

        $pages = [];
        try {
            while (count($pages) < MAX_PAGES) {
                $page = nextPage($job);
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
                http('DELETE', $job, null, [], false);
            } catch (RuntimeException) {
                // The printer drops the job by itself after a while.
            }
        }
    } catch (RuntimeException $error) {
        throw str_starts_with($error->getMessage(), 'scan.') ? $error : new RuntimeException('scan.unreachable');
    }
    if ($pages === []) {
        throw new RuntimeException($source === 'feeder' ? 'scan.feeder_empty' : 'scan.no_page');
    }

    return $pages;
}

function nextPage(string $job): ?string
{
    for ($try = 0; $try < 90; $try++) {
        [$status, $body] = http('GET', $job.'/NextDocument', null, [], false);
        if ($status === 503) {
            usleep(500_000);

            continue;
        }
        if ($status === 404 || $status === 409) {
            return null;
        }
        if ($status !== 200 || ! str_starts_with($body, "\xFF\xD8")) {
            throw new RuntimeException('scan.job_failed');
        }

        return $body;
    }

    throw new RuntimeException('scan.timeout');
}

// ---- DocHamster ----------------------------------------------------------------------

/** @return array{int, mixed} status and decoded JSON (null when there is none) */
function dochamster(string $base, string $path, ?string $token, mixed $json = null, ?string $raw = null, ?string $rawType = null): array
{
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    $body = $raw;
    if ($raw !== null) {
        $headers[] = 'Content-Type: '.$rawType;
    } elseif ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($json, JSON_THROW_ON_ERROR);
    } else {
        $body = '';
    }
    [$status, $answer] = http('POST', $base.$path, $body, $headers, true);
    $decoded = $answer !== '' ? json_decode($answer, true) : null;

    return [$status, $decoded];
}

// ---- main ----------------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    stop('Run this program on the command line.');
}
if (! extension_loaded('curl') || ! extension_loaded('SimpleXML')) {
    stop('PHP needs the curl and SimpleXML extensions.');
}

$server = rtrim((string) setting('DOCHAMSTER_URL', ''), '/');
$printer = printerBase((string) setting('PRINTER_URL', ''));
if ($server === '' || (! str_starts_with($server, 'https://') && setting('ALLOW_INSECURE_HTTP') !== '1')) {
    stop('DOCHAMSTER_URL must be the https:// address of your DocHamster.');
}
$options = array_slice($argv, 1);
$dataDir = rtrim((string) setting('DATA_DIR', is_dir('/data') ? '/data' : __DIR__.'/data'), '/\\');
$tokenFile = $dataDir.'/token';

try {
    $info = printerInfo($printer);
} catch (RuntimeException $error) {
    stop('The printer at PRINTER_URL does not answer as a scanner ('.$error->getMessage().'). Is it on, and is the address right?');
}
say('Printer: '.($info['model'] !== '' ? $info['model'] : $printer).($info['feeder'] ? ' (with document feeder)' : ''));
if (in_array('--check', $options, true)) {
    exit(0);
}

// Link with the one-time code on the first start; afterwards only the key is used.
$token = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
if ($token === '') {
    $code = setting('PAIRING_CODE');
    if ($code === null) {
        stop('First start: set PAIRING_CODE to the code shown in DocHamster (Einstellungen > Scanner).');
    }
    try {
        [$status, $answer] = dochamster($server, '/api/station/pair', null, ['code' => $code, 'name' => setting('STATION_NAME', (string) gethostname())]);
    } catch (RuntimeException $error) {
        stop('Cannot reach DocHamster: '.$error->getMessage());
    }
    if ($status !== 201 || ! is_array($answer) || ! is_string($answer['token'] ?? null)) {
        stop('DocHamster did not accept the code. It works once and for 24 hours; make a new Scan Station in DocHamster.');
    }
    $token = $answer['token'];
    if (! is_dir($dataDir) && ! mkdir($dataDir, 0700, true) && ! is_dir($dataDir)) {
        stop('Cannot create '.$dataDir.' for the key.');
    }
    file_put_contents($tokenFile, $token);
    @chmod($tokenFile, 0600);
    say('Linked with DocHamster. The key is saved in '.$tokenFile.'.');
}

say('Waiting for scan requests from '.$server.' ...');
$failures = 0;
while (true) {
    try {
        [$status, $job] = dochamster($server, '/api/station/poll', $token, ['printer' => ['name' => $info['model'] !== '' ? $info['model'] : $printer, 'feeder' => $info['feeder']]]);
        if ($status === 401) {
            stop('DocHamster no longer knows this station (it was removed). Delete '.$tokenFile.' and link it again with a new code.', 2);
        }
        if ($status === 200 && is_array($job)) {
            $failures = 0;
            handle($server, $printer, $token, $job);
        } elseif ($status !== 204) {
            throw new RuntimeException('DocHamster answered '.$status);
        } else {
            $failures = 0;
        }
    } catch (RuntimeException $error) {
        $failures++;
        say('Problem: '.$error->getMessage().' (trying again)');
    }
    if (in_array('--once', $options, true)) {
        exit(0);
    }
    sleep(min(30, POLL_SECONDS * (1 + $failures)));
}

/**
 * One scan request: scan, send every page, report the end.
 *
 * @param  array<string, mixed>  $job
 */
function handle(string $server, string $printer, string $token, array $job): void
{
    $id = is_string($job['id'] ?? null) ? $job['id'] : '';
    if (preg_match('/^[0-9a-z]{26}$/', $id) !== 1) {
        return;
    }
    say('Scan request '.$id.' ('.($job['source'] ?? '?').', '.($job['dpi'] ?? '?').' dpi)');

    try {
        $pages = scan($printer, (string) ($job['source'] ?? 'platen'), (string) ($job['color'] ?? 'color'), (int) ($job['dpi'] ?? 200));
        foreach ($pages as $number => $page) {
            [$status] = dochamster($server, '/api/station/requests/'.$id.'/pages', $token, null, $page, 'image/jpeg');
            if ($status !== 201) {
                throw new RuntimeException('scan.failed');
            }
            say('Sent page '.($number + 1).' of '.count($pages));
        }
        dochamster($server, '/api/station/requests/'.$id.'/finish', $token, new stdClass);
        say('Done.');
    } catch (RuntimeException $error) {
        $key = str_starts_with($error->getMessage(), 'scan.') ? $error->getMessage() : 'scan.failed';
        say('Failed: '.$key);
        try {
            dochamster($server, '/api/station/requests/'.$id.'/finish', $token, ['error' => $key]);
        } catch (RuntimeException) {
            // DocHamster gives the request up by itself after a few minutes.
        }
    }
}
