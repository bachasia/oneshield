<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'token_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
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
}
