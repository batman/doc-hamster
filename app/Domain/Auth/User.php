<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Org\Membership;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A person using DocHamster (D-012). Owners and members have no password –
 * they sign in with passkeys and a device token. Only tax advisor accounts
 * carry a username and an Argon2id password hash (config/hashing.php).
 *
 * @property string $id
 * @property string|null $username
 * @property string|null $email
 * @property string $display_name
 * @property string $locale
 * @property string|null $password_hash
 * @property string|null $receipt_view How the receipt list is shown (App\Http\Documents\DocumentListing::VIEWS).
 * @property string|null $folder_view The last display mode of the folder view (App\Http\Documents\DocumentListing::FOLDER_VIEWS).
 * @property string|null $receipt_dates The date timeline and calendar go by: doc (receipt date) or upload.
 * @property list<string>|null $document_columns Property ids shown as columns of the details view (A244).
 */
#[UseFactory(UserFactory::class)]
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUlids;

    /** Laravel reads the password from here instead of `password`. */
    protected $authPasswordName = 'password_hash';

    protected $fillable = ['username', 'email', 'display_name', 'locale', 'password_hash'];

    protected $hidden = ['password_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password_hash' => 'hashed', 'document_columns' => 'array'];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * @return HasMany<Passkey, $this>
     */
    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    public function hasPassword(): bool
    {
        return $this->password_hash !== null;
    }
}
