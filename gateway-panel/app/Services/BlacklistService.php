<?php

namespace App\Services;

use App\Models\BlacklistEntry;
use App\Models\User;

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
     * Build the merged blacklist payload for a user.
     * Merges customer-specific entries with system entries per enabled toggle.
     *
     * Used by both the API endpoint and the heartbeat push (Option C).
     *
     * @return array{ emails: string[], cities: string[], states: string[], zipcodes: string[] }
     */
    public function getListForUser(User $user): array
    {
        $customerQ = BlacklistEntry::where('is_system', false)->where('user_id', $user->id);
        $systemQ   = BlacklistEntry::where('is_system', true);

        $emails   = (clone $customerQ)->where('type', 'email')->pluck('value')->all();
        $cities   = (clone $customerQ)->where('type', 'city')->pluck('value')->all();
        $states   = (clone $customerQ)->where('type', 'state')->pluck('value')->all();
        $zipcodes = (clone $customerQ)->where('type', 'zipcode')->pluck('value')->all();

        if ($user->use_system_blacklist_emails) {
            $emails = array_values(array_unique(array_merge($emails, (clone $systemQ)->where('type', 'email')->pluck('value')->all())));
        }
        if ($user->use_system_blacklist_cities) {
            $cities = array_values(array_unique(array_merge($cities, (clone $systemQ)->where('type', 'city')->pluck('value')->all())));
        }
        if ($user->use_system_blacklist_states) {
            $states = array_values(array_unique(array_merge($states, (clone $systemQ)->where('type', 'state')->pluck('value')->all())));
        }
        if ($user->use_system_blacklist_zipcodes) {
            $zipcodes = array_values(array_unique(array_merge($zipcodes, (clone $systemQ)->where('type', 'zipcode')->pluck('value')->all())));
        }

        return [
            'emails'   => $emails,
            'cities'   => $cities,
            'states'   => $states,
            'zipcodes' => $zipcodes,
        ];
    }

    /**
     * Normalize a field value: lowercase + trim.
     */
    public function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
