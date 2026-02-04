<?php

namespace ArkhamDistrict\ApiKeys\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tokenable_type   Polymorphic owner class (e.g., App\Models\User).
 * @property int $tokenable_id     Polymorphic owner ID.
 * @property string $name             Human-readable label (e.g., "Production").
 * @property string $prefix           Token prefix including environment (e.g., "sk_live_").
 * @property string $secret_key       Ed25519 secret key (automatically encrypted/decrypted).
 * @property string $public_key       Ed25519 public key (plain text, unique).
 * @property array|null $abilities        JSON array of granted abilities, null or ["*"] means all.
 * @property Carbon|null $last_used_at Timestamp of last authenticated request.
 * @property Carbon|null $expires_at   Expiration timestamp, null means never expires.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ApiKey extends Model
{
    protected $fillable = [
        'name',
        'prefix',
        'secret_key',
        'public_key',
        'abilities',
        'expires_at',
    ];

    /**
     * Get the attribute casts.
     *
     * - `abilities`: Cast to/from JSON array.
     * - `secret_key`: Encrypted at rest using Laravel's encryption.
     * - `last_used_at`: Cast to Carbon datetime.
     * - `expires_at`: Cast to Carbon datetime.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'secret_key' => 'encrypted',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Get the owning tokenable model (polymorphic).
     *
     * Typically resolves to a User model but supports any Eloquent model
     * that uses the HasApiKeys trait.
     *
     * @return MorphTo<Model, $this>
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Determine if the API key has expired.
     *
     * A key is considered expired if `expires_at` is set and is in the past.
     * Keys without an expiration date never expire.
     *
     * @return bool True if the key is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Determine if the API key has a given ability.
     *
     * Returns true if:
     * - The `abilities` field is null (unrestricted), or
     * - The `abilities` field contains the wildcard `["*"]`, or
     * - The given ability is present in the `abilities` array.
     *
     * @param  string  $ability  The ability to check (e.g., "invoices:read").
     * @return bool True if the ability is granted.
     */
    public function can(string $ability): bool
    {
        if ($this->abilities === null || $this->abilities === ['*']) {
            return true;
        }

        return in_array($ability, $this->abilities, true);
    }

    /**
     * Determine if the API key does not have a given ability.
     *
     * Inverse of the `can()` method.
     *
     * @param  string  $ability  The ability to check (e.g., "invoices:delete").
     * @return bool True if the ability is NOT granted.
     */
    public function cant(string $ability): bool
    {
        return !$this->can($ability);
    }
}
