<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use App\Domain\Scanning\ScannerAddress;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * "Im Netzwerk suchen" (PHONE, issue #37): when DocHamster runs in the home
 * network, it looks for scanners itself – every address of its own /24
 * private network is asked for the eSCL capabilities, 64 at a time, one
 * second each. A server on the internet has no private address and finds
 * nothing; then the Scan Station is the way (it does the same search at home).
 *
 * Runs only when an admin taps the button (D-020, App\Jobs\Net), calls only
 * private addresses (ScannerAddress::isPrivate).
 */
final class ScannerDiscovery
{
    private const BATCH = 64;

    /**
     * @return list<array{url: string, model: string}>
     */
    public function find(): array
    {
        $own = $this->ownAddress();
        if ($own === null) {
            return [];
        }
        $prefix = implode('.', array_slice(explode('.', $own), 0, 3));
        $hosts = array_values(array_filter(array_map(static fn (int $i): string => $prefix.'.'.$i, range(1, 254)), static fn (string $ip): bool => $ip !== $own));

        $found = [];
        foreach (array_chunk($hosts, self::BATCH) as $batch) {
            $responses = Http::pool(static fn (Pool $pool): array => array_map(
                static fn (string $ip) => $pool->as($ip)->withoutVerifying()->withUserAgent('DocHamster')->connectTimeout(1)->timeout(2)->withoutRedirecting()
                    ->get('http://'.$ip.'/eSCL/ScannerCapabilities'),
                $batch,
            ));
            foreach ($responses as $ip => $response) {
                if (! $response instanceof Response || ! $response->successful() || ! str_contains($response->body(), 'ScannerCapabilities')) {
                    continue;
                }
                $xml = @simplexml_load_string($response->body(), \SimpleXMLElement::class, LIBXML_NONET);
                $model = $xml !== false ? trim((string) ($xml->xpath('//*[local-name()="MakeAndModel"]')[0] ?? '')) : '';
                $found[] = ['url' => 'http://'.$ip, 'model' => $model !== '' ? $model : (string) $ip];
            }
        }

        return $found;
    }

    /** The server's own private IPv4 address, if it has one. */
    private function ownAddress(): ?string
    {
        $socket = function_exists('socket_create') ? @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP) : false;
        $ip = null;
        if ($socket !== false) {
            // A UDP "connect" sends nothing; it only picks the address the system would use.
            if (@socket_connect($socket, '192.168.0.1', 9) && @socket_getsockname($socket, $address)) {
                $ip = is_string($address) ? $address : null;
            }
            socket_close($socket);
        }
        $ip ??= gethostbyname((string) gethostname());

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ScannerAddress::isPrivate($ip) ? $ip : null;
    }
}
