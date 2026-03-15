<?php

namespace App\Services;

use App\Models\BlacklistEntry;

class BlacklistService
{
    /**
     * Check if an email or billing address matches any blacklist entry.
     */
    public function isBlacklisted(string $email, string $address): bool
    {
        $emailLower = strtolower(trim($email));
        $addrNorm   = $this->normalizeAddress($address);

        if ($emailLower && BlacklistEntry::where('type', 'email')->where('value', $emailLower)->exists()) {
            return true;
        }

        if ($addrNorm && BlacklistEntry::where('type', 'address')->where('value', $addrNorm)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Normalize an address for consistent comparison:
     * lowercase, remove punctuation, collapse spaces, abbreviate common street types.
     */
    public function normalizeAddress(string $addr): string
    {
        $addr = strtolower(trim($addr));
        $addr = preg_replace('/[^\w\s]/', '', $addr);   // remove punctuation
        $addr = preg_replace('/\s+/', ' ', $addr);       // collapse spaces

        // Common street type abbreviations
        $replacements = [
            'street'    => 'st',
            'avenue'    => 'ave',
            'boulevard' => 'blvd',
            'drive'     => 'dr',
            'lane'      => 'ln',
            'road'      => 'rd',
            'court'     => 'ct',
            'place'     => 'pl',
        ];

        foreach ($replacements as $full => $abbr) {
            $addr = preg_replace('/\b' . $full . '\b/', $abbr, $addr);
        }

        return trim($addr);
    }
}
