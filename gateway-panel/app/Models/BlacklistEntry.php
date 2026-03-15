<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BlacklistEntry extends Model
{
    protected $table = 'blacklist_entries';

    /** Supported types: email, city, state, zipcode */
    protected $fillable = [
        'type',
        'value',
        'notes',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    // ── Static helpers ────────────────────────────────────────────────────

    /**
     * Check if any of the given customer fields match a blacklist entry.
     *
     * @param array{email: string, city: string, state: string, zipcode: string} $fields
     */
    public static function isBlacklisted(array $fields): bool
    {
        $map = [
            'email'   => 'email',
            'city'    => 'city',
            'state'   => 'state',
            'zipcode' => 'zipcode',
        ];

        foreach ($map as $field => $type) {
            $value = strtolower(trim($fields[$field] ?? ''));
            if ($value && static::where('type', $type)->where('value', $value)->exists()) {
                return true;
            }
        }

        return false;
    }
}
