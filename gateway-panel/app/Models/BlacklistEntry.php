<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BlacklistEntry extends Model
{
    protected $table = 'blacklist_entries';

    protected $fillable = [
        'type',
        'value',
        'source',
        'notes',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeFromSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    // ── Static helpers ────────────────────────────────────────────────────

    /**
     * Check if an email or normalized address matches any blacklist entry.
     */
    public static function isBlacklisted(string $email, string $address): bool
    {
        $emailLower   = strtolower(trim($email));
        $addrNorm     = app(\App\Services\BlacklistService::class)->normalizeAddress($address);

        if ($emailLower && static::where('type', 'email')->where('value', $emailLower)->exists()) {
            return true;
        }

        if ($addrNorm && static::where('type', 'address')->where('value', $addrNorm)->exists()) {
            return true;
        }

        return false;
    }
}
