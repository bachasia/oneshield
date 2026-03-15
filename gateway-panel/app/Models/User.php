<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'token_secret',
        'cors_origins',
        'is_super_admin',
        'use_system_blacklist',
        'blacklist_action',
        'trap_shield_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'token_secret',
    ];

    protected $casts = [
        'is_super_admin' => 'boolean',
        'cors_origins'   => 'array',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'password'             => 'hashed',
            'is_super_admin'       => 'boolean',
            'cors_origins'         => 'array',
            'use_system_blacklist' => 'boolean',
            'trap_shield_id'       => 'integer',
        ];
    }

    public function shieldSites(): HasMany
    {
        return $this->hasMany(ShieldSite::class);
    }

    public function siteGroups(): HasMany
    {
        return $this->hasMany(SiteGroup::class);
    }

    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(Transaction::class, ShieldSite::class);
    }

    public function gatewayTokens(): HasMany
    {
        return $this->hasMany(GatewayToken::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The current active subscription (latest active/trial).
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
                    ->whereIn('status', ['active', 'trial'])
                    ->where(fn ($q) => $q->whereNull('expires_at')
                                         ->orWhere('expires_at', '>', now()))
                    ->latestOfMany();
    }

    /**
     * Can this tenant create another shield site?
     */
    public function canCreateShieldSite(): bool
    {
        $sub = $this->activeSubscription;
        if (! $sub) return false;

        $max     = $sub->plan->max_shield_sites ?? 0;
        $current = $this->shieldSites()->count();

        return $current < $max;
    }

    /**
     * Human-readable message when shield site limit is reached.
     */
    public function shieldSiteLimitMessage(): string
    {
        $sub  = $this->activeSubscription;
        $plan = $sub?->plan?->label ?? 'Free';
        $max  = $sub?->plan?->max_shield_sites ?? 0;

        return "Your {$plan} plan allows {$max} shield site(s). Please upgrade to add more.";
    }

    /**
     * How many shield sites the active plan allows.
     */
    public function shieldSiteLimit(): int
    {
        return $this->activeSubscription?->plan?->max_shield_sites ?? 0;
    }
}
