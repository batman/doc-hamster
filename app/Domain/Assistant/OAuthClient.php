<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An assistant that registered itself (D-040, OAuth dynamic client registration, RFC 7591) – e.g. Claude when a person
 * adds docHamster as a connector. Registering grants nothing: only a person's "Erlauben" on the consent page makes a
 * connection. Not tied to an organisation; it exists before anybody signed in.
 *
 * @property string $id
 * @property string $client_id
 * @property string|null $secret_hash
 * @property string $name
 * @property list<string> $redirect_uris
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 */
final class OAuthClient extends Model
{
    use HasUlids;

    protected $table = 'oauth_clients';

    protected $fillable = ['client_id', 'secret_hash', 'name', 'redirect_uris', 'last_used_at'];

    protected $hidden = ['secret_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['redirect_uris' => 'array', 'last_used_at' => 'datetime'];
    }

    /**
     * @param  list<string>  $redirectUris
     * @return array{self, string|null} the client and its secret (only for a confidential client, only now)
     */
    public static function register(string $name, array $redirectUris, bool $confidential): array
    {
        $secret = $confidential ? Str::random(48) : null;
        $client = self::query()->create([
            'client_id' => 'dhc_'.Str::random(32),
            'secret_hash' => $secret === null ? null : hash('sha256', $secret),
            'name' => $name,
            'redirect_uris' => $redirectUris,
        ]);

        return [$client, $secret];
    }

    public static function byClientId(?string $clientId): ?self
    {
        return is_string($clientId) && $clientId !== '' ? self::query()->where('client_id', $clientId)->first() : null;
    }

    public function allowsRedirect(string $uri): bool
    {
        return in_array($uri, $this->redirect_uris, true);
    }

    /** A public client proves itself with PKCE only; a confidential one also with its secret. */
    public function secretMatches(?string $secret): bool
    {
        return $this->secret_hash === null || (is_string($secret) && hash_equals($this->secret_hash, hash('sha256', $secret)));
    }

    /** A redirect address an assistant may use: https, or http only to this computer (a local client). */
    public static function acceptableRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return false;
        }

        return $parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true));
    }
}
