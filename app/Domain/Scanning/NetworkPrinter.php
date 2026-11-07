<?php

declare(strict_types=1);

namespace App\Domain\Scanning;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Org\CurrentOrg;
use App\Jobs\Net\IppClient;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * "Auf Drucker drucken" (PHONE, issue #37): sends a stored file to the network
 * printer that also scans. With a printer address in the settings the server
 * prints itself (same home network); otherwise an online Scan Station takes
 * the job and prints at home.
 */
final readonly class NetworkPrinter
{
    public function __construct(private CurrentOrg $org) {}

    /** True when printing can work: a printer address or a linked Scan Station. */
    public function available(): bool
    {
        return $this->org->isSet() && ($this->directAddress() !== null || ScanStation::linked());
    }

    /**
     * @return string translation key of the outcome, `print.sent` or `print.queued`
     *
     * @throws RuntimeException with a translation key `print.*`
     */
    public function send(DocumentFile $file, Document $document): string
    {
        $address = $this->directAddress();
        if ($address !== null) {
            $bytes = Storage::disk('local')->get($file->path);
            if (! is_string($bytes)) {
                throw new RuntimeException('print.failed');
            }
            (new IppClient($address))->print($bytes, $file->mime, $document->title);

            return 'print.sent';
        }

        $station = ScanStation::online();
        if ($station === null) {
            throw new RuntimeException(ScanStation::linked() ? 'print.station_offline' : 'print.not_set_up');
        }
        ScanRequest::query()->create([
            'org_id' => $this->org->id(),
            'user_id' => $this->org->membership()->user_id,
            'station_id' => $station->id,
            'kind' => 'print',
            'file_id' => $file->id,
            'source' => 'print',
            'color' => 'color',
            'dpi' => 0,
        ]);

        return 'print.queued';
    }

    /** The printer address from the settings, checked again on every use. */
    private function directAddress(): ?string
    {
        $url = $this->org->organization()->settings_json['scanner']['url'] ?? null;
        if (! is_string($url)) {
            return null;
        }
        try {
            return ScannerAddress::normalise($url);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
