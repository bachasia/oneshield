<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'label',
        'price_usd',
        'max_shield_sites',
        'is_active',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'max_shield_sites' => 'integer',
        'price_usd'        => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Whether this plan has an effective unlimited cap.
     */
    public function isUnlimited(): bool
    {
        return $this->max_shield_sites >= 999;
    }

    /**
     * Human-readable price string.
     */
    public function priceLabel(): string
    {
        if ($this->price_usd === 0) {
            return match ($this->name) {
                'trial'      => 'Free',
                'enterprise' => 'Contact us',
                default      => 'Free',
            };
        }

        return '$' . $this->price_usd . '/mo';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
