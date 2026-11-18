<?php

declare(strict_types=1);

namespace DocHamsterModules\Revolut;

use App\Domain\Org\CurrentOrg;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Revolut connection of this installation (one row per organisation in m_revolut_settings). The API key and the
 * private signing key are kept encrypted (D-007) and never shown again; the public key is shown to be uploaded in the
 * Revolut API Console. accounts = the Revolut accounts last seen; mapping = Revolut account id → docHamster account id
 * ("new" = make one).
 */
final class Settings
{
    /** @param array<string, mixed> $row */
    private function __construct(private array $row) {}

    public static function load(): self
    {
        $org = app(CurrentOrg::class)->id();
        $row = DB::table('m_revolut_settings')->where('org_id', $org)->first();
        if ($row === null) {
            $row = ['id' => (string) Str::ulid(), 'org_id' => $org, 'environment' => 'live', 'drafts' => false, 'created_at' => now(), 'updated_at' => now()];
            DB::table('m_revolut_settings')->insert($row);
        }

        return new self((array) $row);
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): self
    {
        $this->row = [...$this->row, ...$values, 'updated_at' => now()];
        DB::table('m_revolut_settings')->where('id', $this->row['id'])->update([...$values, 'updated_at' => now()]);

        return $this;
    }

    public function environment(): string
    {
        return $this->row['environment'] === 'sandbox' ? 'sandbox' : 'live';
    }

    public function apiKey(): ?string
    {
        return $this->secret('api_key');
    }

    public function keyHint(): ?string
    {
        $key = $this->apiKey();

        return $key === null ? null : '…'.substr($key, -4);
    }

    public function setApiKey(string $key): self
    {
        return $this->save(['api_key' => Crypt::encryptString(trim($key)), 'last_error' => null]);
    }

    /** The private Ed25519 key (64 bytes, sodium secret key), or null before one was made. */
    public function signingKey(): ?string
    {
        $value = $this->secret('signing_private');

        return $value === null ? null : (base64_decode($value, true) ?: null);
    }

    public function publicPem(): ?string
    {
        return is_string($this->row['signing_public'] ?? null) ? $this->row['signing_public'] : null;
    }

    /** Makes a new signing key pair; the public half is shown to be uploaded, the private half never leaves the server. */
    public function makeSigningKey(): self
    {
        $pair = Signer::generate();

        return $this->save(['signing_private' => Crypt::encryptString(base64_encode($pair['secret'])), 'signing_public' => $pair['pem']]);
    }

    /** @return list<array{id: string, name: string, currency: string, iban: ?string, state: string}> */
    public function accounts(): array
    {
        $out = [];
        foreach ($this->json('accounts') as $a) {
            if (is_array($a) && is_string($a['id'] ?? null)) {
                $out[] = ['id' => $a['id'], 'name' => (string) ($a['name'] ?? ''), 'currency' => (string) ($a['currency'] ?? 'EUR'),
                    'iban' => is_string($a['iban'] ?? null) ? $a['iban'] : null, 'state' => (string) ($a['state'] ?? 'active')];
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    public function mapping(): array
    {
        return $this->json('mapping');
    }

    public function drafts(): bool
    {
        return (bool) ($this->row['drafts'] ?? false);
    }

    public function confirmed(): bool
    {
        return ($this->row['confirmed_at'] ?? null) !== null;
    }

    public function time(string $field): ?Carbon
    {
        $value = $this->row[$field] ?? null;

        return $value === null ? null : Carbon::parse((string) $value);
    }

    public function error(): ?string
    {
        return is_string($this->row['last_error'] ?? null) ? $this->row['last_error'] : null;
    }

    private function secret(string $field): ?string
    {
        $value = $this->row[$field] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<mixed> */
    private function json(string $field): array
    {
        $value = $this->row[$field] ?? null;
        $data = is_string($value) ? json_decode($value, true) : $value;

        return is_array($data) ? $data : [];
    }
}
