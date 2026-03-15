<?php

namespace App\Services;

use App\Models\BlacklistEntry;

class BlacklistService
{
    /**
     * Check if any customer field matches a blacklist entry.
     * Each field is compared against its respective type (email, city, state, zipcode).
     */
    public function isBlacklisted(string $email, string $city, string $state, string $zipcode): bool
    {
        return BlacklistEntry::isBlacklisted([
            'email'   => $email,
            'city'    => $city,
            'state'   => $state,
            'zipcode' => $zipcode,
        ]);
    }

    /**
     * Normalize a field value: lowercase + trim.
     */
    public function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
