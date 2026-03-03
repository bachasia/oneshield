<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class MeshSite extends Model
{
    protected $fillable = [
        'user_id',
        'group_id',
        'name',
        'url',
        'site_key',
        'paypal_client_id',
        'paypal_secret',
        'paypal_mode',
        'paypal_enabled',
        'stripe_public_key',
        'stripe_secret_key',
        'stripe_mode',
        'stripe_enabled',
        'stripe_webhook_secret',
        'airwallex_client_id',
        'airwallex_api_key',
        'receive_cycle',
        'paypal_income_limit',
        'paypal_max_per_order',
        'stripe_income_limit',
        'stripe_max_per_order',
        'is_active',
        'disabled_at',
        'failure_count',
        'last_heartbeat_at',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'paypal_enabled'       => 'boolean',
        'stripe_enabled'       => 'boolean',
        'last_heartbeat_at'    => 'datetime',
        'disabled_at'          => 'datetime',
        'failure_count'        => 'integer',
        'paypal_income_limit'  => 'float',
        'paypal_max_per_order' => 'float',
        'stripe_income_limit'  => 'float',
        'stripe_max_per_order' => 'float',
    ];

    // Encrypted attributes
    protected function paypalClientId(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    protected function paypalSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    protected function stripePublicKey(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    protected function stripeSecretKey(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    protected function airwallexClientId(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    protected function airwallexApiKey(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    protected function stripeWebhookSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(SiteGroup::class, 'group_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'site_id');
    }

    /**
     * Check if the site supports a given gateway.
     */
    public function supportsGateway(string $gateway): bool
    {
        return match($gateway) {
            'paypal'    => !empty($this->paypal_client_id) && !empty($this->paypal_secret) && $this->paypal_enabled,
            'stripe'    => !empty($this->stripe_public_key) && !empty($this->stripe_secret_key) && $this->stripe_enabled,
            'airwallex' => !empty($this->airwallex_client_id) && !empty($this->airwallex_api_key),
            default     => false,
        };
    }

    /**
     * Check if gateway credentials are configured (regardless of enabled flag).
     */
    public function hasGatewayCredentials(string $gateway): bool
    {
        return match($gateway) {
            'paypal'    => !empty($this->paypal_client_id) && !empty($this->paypal_secret),
            'stripe'    => !empty($this->stripe_public_key) && !empty($this->stripe_secret_key),
            'airwallex' => !empty($this->airwallex_client_id) && !empty($this->airwallex_api_key),
            default     => false,
        };
    }

    /**
     * Scope: only active sites.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
