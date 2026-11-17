<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The Steckbrief of a module (A65): dochamster-module.json – published next to the module's zip in its GitHub
 * release and inside the zip. Everything a person sees before installing comes from here; everything is checked.
 *
 * {"key": "kassenbuch", "name": "Kassenbuch", "description": "…", "version": "1.2.0", "provider": "Max Mustermann",
 *  "homepage": "https://github.com/example/dochamster-kassenbuch", "api": 1, "rights": ["documents_read"],
 *  "zip_sha256": "…"}   (zip_sha256 only in the release asset, written by the template's release workflow)
 */
final readonly class Manifest
{
    /** @param list<string> $rights */
    private function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $version,
        public string $provider,
        public string $homepage,
        public int $api,
        public array $rights,
        public ?string $zipSha256,
    ) {}

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidArgumentException with a German reason the page can show
     */
    public static function from(array $data): self
    {
        $text = static fn (string $field, int $max): string => is_string($data[$field] ?? null) && trim($data[$field]) !== '' && mb_strlen($data[$field]) <= $max
            ? trim($data[$field])
            : throw new InvalidArgumentException(__('modules.bad_manifest', ['field' => $field]));
        $key = $text('key', 40);
        if (preg_match('/^[a-z][a-z0-9-]{2,39}$/', $key) !== 1) {
            throw new InvalidArgumentException(__('modules.bad_manifest', ['field' => 'key']));
        }
        $version = $text('version', 30);
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new InvalidArgumentException(__('modules.bad_manifest', ['field' => 'version']));
        }
        $rights = $data['rights'] ?? [];
        if (! is_array($rights) || array_diff($rights, ModuleApi::RIGHTS) !== []) {
            throw new InvalidArgumentException(__('modules.bad_manifest', ['field' => 'rights']));
        }
        $homepage = is_string($data['homepage'] ?? null) && str_starts_with($data['homepage'], 'https://') ? $data['homepage'] : '';
        $sha = is_string($data['zip_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $data['zip_sha256']) === 1 ? $data['zip_sha256'] : null;

        return new self($key, $text('name', 60), $text('description', 300), $version, $text('provider', 80), $homepage,
            is_int($data['api'] ?? null) ? $data['api'] : 0, array_values(array_unique(array_map('strval', $rights))), $sha);
    }

    /** The only namespace the module's classes may live in, so it can never replace a class of docHamster. */
    public function namespace(): string
    {
        return 'DocHamsterModules\\'.Str::studly($this->key).'\\';
    }

    /** The entry class: <namespace>Module. */
    public function entry(): string
    {
        return $this->namespace().'Module';
    }

    /** Prefix of every table the module may create; "Daten auch löschen" drops exactly these (F18). */
    public function tablePrefix(): string
    {
        return 'm_'.str_replace('-', '_', $this->key).'_';
    }

    public function fits(): bool
    {
        return $this->api === ModuleApi::VERSION;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'description' => $this->description, 'version' => $this->version, 'provider' => $this->provider,
            'homepage' => $this->homepage, 'api' => $this->api, 'rights' => $this->rights, 'zip_sha256' => $this->zipSha256];
    }
}
