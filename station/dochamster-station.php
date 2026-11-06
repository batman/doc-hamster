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
 *   PRINTER_URL      optional: address of the printer, e.g. 192.168.0.20. Without it the
 *                    station finds the printer by itself (Bonjour, else a quick look at
 *                    the home network); with several devices PRINTER_NAME picks one
 *   PAIRING_CODE     the one-time code from DocHamster (only for the first start)
 *   STATION_NAME     optional name shown in DocHamster
 *   DATA_DIR         where the station keeps its key (default: ./data, in Docker /data)
 *
 * Options: --list (show the scanners found in the network), --check (look for the
 * printer, then stop), --once (one round, then stop)
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

// ---- finding the printer by itself ---------------------------------------------------

/** This computer's address in the home network (no packet is sent), or null. */
function localAddress(): ?string
{
    $socket = @stream_socket_client('udp://192.0.2.1:9', $errno, $message, 1);
    if ($socket === false) {
        return null;
    }
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $ip = explode(':', $name)[0];

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && isPrivate($ip) ? $ip : null;
}

/** A DNS-SD question "who offers <service>?" for multicast DNS. */
function dnsQuestion(string $service): string
{
    $packet = pack('nnnnnn', 0, 0, 1, 0, 0, 0);
    foreach (explode('.', $service) as $label) {
        $packet .= chr(strlen($label)).$label;
    }

    return $packet."\x00".pack('nn', 12, 1);
}

/**
 * Devices that announce themselves as network scanners (Bonjour/AirScan, `_uscan._tcp`).
 * Their answers come back to us directly, so the sender's address is all we need.
 *
 * @return list<string> private IPv4 addresses
 */
function mdnsHosts(): array
{
    $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $message, STREAM_SERVER_BIND);
    if ($socket === false) {
        return [];
    }
    foreach (['_uscan._tcp.local', '_uscans._tcp.local'] as $service) {
        @stream_socket_sendto($socket, dnsQuestion($service), 0, '224.0.0.251:5353');
    }
    stream_set_blocking($socket, false);
    $hosts = [];
    $end = microtime(true) + 3.0;
    while (microtime(true) < $end) {
        $read = [$socket];
        $write = $except = null;
        if (stream_select($read, $write, $except, 0, 250_000) < 1) {
            continue;
        }
        $data = @stream_socket_recvfrom($socket, 9000, 0, $peer);
        $ip = is_string($peer) ? explode(':', $peer)[0] : '';
        if ($data !== false && $data !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && isPrivate($ip)) {
            $hosts[$ip] = true;
        }
    }
    fclose($socket);

    return array_keys($hosts);
}

/**
 * The last resort when nothing answered by Bonjour: ask every address of this
 * computer's /24 network whether it speaks eSCL (a few seconds).
 *
 * @return list<string> base addresses that answered as a scanner
 */
function sweepNetwork(): array
{
    $local = localAddress();
    if ($local === null) {
        return [];
    }
    $prefix = implode('.', array_slice(explode('.', $local), 0, 3));
    $bases = [];
    foreach (range(1, 254) as $host) {
        $bases[] = 'http://'.$prefix.'.'.$host;
        $bases[] = 'https://'.$prefix.'.'.$host;
    }

    // In batches: a few dozen requests at a time are enough and easy on the router.
    $found = [];
    foreach (array_chunk($bases, 64) as $batch) {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($batch as $base) {
            $curl = curl_init($base.'/eSCL/ScannerCapabilities');
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 900,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT => 'DocHamster-Station',
            ]);
            curl_multi_add_handle($multi, $curl);
            $handles[$base] = $curl;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.2);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $base => $curl) {
            if (curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200 && str_contains((string) curl_multi_getcontent($curl), 'ScannerCapabilities')) {
                $found[] = $base;
            }
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
        }
        curl_multi_close($multi);
    }

    return $found;
}

/**
 * Every scanner this computer can reach: announced by Bonjour, else found by asking the network.
 *
 * @return list<array{url: string, model: string, feeder: bool}>
 */
function discoverPrinters(): array
{
    $bases = [];
    foreach (mdnsHosts() as $ip) {
        foreach (['http://'.$ip, 'https://'.$ip, 'http://'.$ip.':8080'] as $base) {
            $bases[] = $base;
        }
    }
    $found = [];
    foreach ($bases as $base) {
        try {
            $found[$base] = ['url' => $base] + printerInfo($base);
        } catch (RuntimeException) {
            // Not an eSCL scanner on that port.
        }
    }
    if ($found === []) {
        foreach (sweepNetwork() as $base) {
            try {
                $found[$base] = ['url' => $base] + printerInfo($base);
            } catch (RuntimeException) {
                // Went away between the two requests.
            }
        }
    }

    // One device answers on http and https: keep the first address per model and host.
    $unique = [];
    foreach ($found as $device) {
        $key = (string) parse_url($device['url'], PHP_URL_HOST);
        $unique[$key] ??= $device;
    }

    return array_values($unique);
}

/**
 * The printer to use: the one named in PRINTER_URL, or what was found by itself.
 *
 * @return array{url: string, model: string, feeder: bool}|null
 */
function findPrinter(?string $configured, ?string $nameHint): ?array
{
    if ($configured !== null) {
        $base = printerBase($configured);
        try {
            return ['url' => $base] + printerInfo($base);
        } catch (RuntimeException) {
            return null;
        }
    }
    $devices = discoverPrinters();
    if ($devices === []) {
        return null;
    }
    if (count($devices) > 1) {
        say('Several scanners found: '.implode(', ', array_map(static fn (array $d): string => ($d['model'] !== '' ? $d['model'] : 'unknown').' ('.$d['url'].')', $devices)).'. Set PRINTER_NAME or PRINTER_URL to choose.');
    }
    if ($nameHint !== null) {
        foreach ($devices as $device) {
            if (stripos($device['model'], $nameHint) !== false) {
                return $device;
            }
        }
    }

    return $devices[0];
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
if ($server === '' || (! str_starts_with($server, 'https://') && setting('ALLOW_INSECURE_HTTP') !== '1')) {
    stop('DOCHAMSTER_URL must be the https:// address of your DocHamster.');
}
$configured = setting('PRINTER_URL');
$nameHint = setting('PRINTER_NAME');
$options = array_slice($argv, 1);
$dataDir = rtrim((string) setting('DATA_DIR', is_dir('/data') ? '/data' : __DIR__.'/data'), '/\\');
$tokenFile = $dataDir.'/token';

if (in_array('--list', $options, true)) {
    say('Looking for scanners in your network ...');
    $devices = discoverPrinters();
    foreach ($devices as $device) {
        say(($device['model'] !== '' ? $device['model'] : 'unknown device').' at '.$device['url'].($device['feeder'] ? ' (with document feeder)' : ''));
    }
    exit($devices === [] ? 1 : 0);
}

/**
 * Looks for the printer and says what it found.
 *
 * @return array{url: string, model: string, feeder: bool}|null
 */
function searchPrinter(?string $configured, ?string $nameHint): ?array
{
    say($configured !== null ? 'Contacting the printer ...' : 'Looking for a printer in your network ...');
    $found = findPrinter($configured, $nameHint);
    if ($found === null) {
        say($configured !== null ? 'The printer at PRINTER_URL does not answer as a scanner. Is it on, and is the address right?' : 'No scanner found yet. Is the printer on and in the same network as this computer? (Docker: use --network host.)');

        return null;
    }
    say('Printer: '.($found['model'] !== '' ? $found['model'] : $found['url']).($found['feeder'] ? ' (with document feeder)' : ''));

    return $found;
}

$printer = searchPrinter($configured, $nameHint);
$searched = time();
$verified = time();
if (in_array('--check', $options, true)) {
    exit($printer === null ? 1 : 0);
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
    // The printer may be switched off or get a new address: look again every 30 seconds, check it every 10 minutes.
    if ($printer === null && time() - $searched >= 30) {
        $printer = searchPrinter($configured, $nameHint);
        $searched = time();
        $verified = time();
    } elseif ($printer !== null && time() - $verified >= 600) {
        try {
            printerInfo($printer['url']);
        } catch (RuntimeException) {
            say('The printer no longer answers.');
            $printer = null;
            $searched = 0;
        }
        $verified = time();
    }

    try {
        $report = $printer === null ? [] : ['printer' => ['name' => $printer['model'] !== '' ? $printer['model'] : $printer['url'], 'feeder' => $printer['feeder']]];
        [$status, $job] = dochamster($server, '/api/station/poll', $token, $report);
        if ($status === 401) {
            stop('DocHamster no longer knows this station (it was removed). Delete '.$tokenFile.' and link it again with a new code.', 2);
        }
        if ($status === 200 && is_array($job)) {
            $failures = 0;
            if (handle($server, $printer['url'] ?? null, $token, $job)) {
                $printer = null;
                $searched = 0;
            }
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
 * @return bool true when the printer could not be reached (look for it again)
 */
function handle(string $server, ?string $printer, string $token, array $job): bool
{
    $id = is_string($job['id'] ?? null) ? $job['id'] : '';
    if (preg_match('/^[0-9a-z]{26}$/', $id) !== 1) {
        return false;
    }
    say('Scan request '.$id.' ('.($job['source'] ?? '?').', '.($job['dpi'] ?? '?').' dpi)');

    $unreachable = false;
    try {
        if ($printer === null) {
            throw new RuntimeException('scan.unreachable');
        }
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
        $unreachable = $key === 'scan.unreachable';
        say('Failed: '.$key);
        try {
            dochamster($server, '/api/station/requests/'.$id.'/finish', $token, ['error' => $key]);
        } catch (RuntimeException) {
            // DocHamster gives the request up by itself after a few minutes.
        }
    }

    return $unreachable;
}
