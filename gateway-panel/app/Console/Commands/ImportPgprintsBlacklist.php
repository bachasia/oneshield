<?php

namespace App\Console\Commands;

use App\Models\BlacklistEntry;
use App\Services\BlacklistService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportPgprintsBlacklist extends Command
{
    protected $signature   = 'blacklist:import-pgprints';
    protected $description = 'Fetch and import the pgprints.io test-buyer blacklist (run once on deployment)';

    public function handle(BlacklistService $blacklistService): int
    {
        $this->info('Fetching pgprints.io blacklist...');

        try {
            $response = Http::timeout(15)->get('https://pgprints.io/test-buy-address/');
        } catch (\Throwable $e) {
            $this->error('Failed to fetch pgprints.io: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error('HTTP ' . $response->status() . ' from pgprints.io');
            return self::FAILURE;
        }

        $html = $response->body();

        // Extract emails — look for patterns like user@domain.tld
        preg_match_all('/[\w._%+\-]+@[\w.\-]+\.[a-zA-Z]{2,}/', $html, $emailMatches);
        $emails = array_unique(array_map('strtolower', $emailMatches[0]));

        // Extract addresses — look for list items or paragraphs containing address-like text
        // pgprints typically lists them in <li> or <p> tags
        preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $html, $liMatches);
        preg_match_all('/<p[^>]*>(.*?)<\/p>/si', $html, $pMatches);

        $rawAddresses = [];
        foreach (array_merge($liMatches[1] ?? [], $pMatches[1] ?? []) as $raw) {
            $text = strip_tags($raw);
            $text = html_entity_decode(trim($text));
            // Skip if it looks like an email or too short
            if (str_contains($text, '@') || strlen($text) < 5) {
                continue;
            }
            // Keep lines that look like addresses (contain digits + alpha)
            if (preg_match('/\d/', $text) && preg_match('/[a-zA-Z]/', $text)) {
                $rawAddresses[] = $text;
            }
        }

        $addresses = array_unique(array_filter(array_map(
            fn ($a) => $blacklistService->normalizeAddress($a),
            $rawAddresses
        )));

        $emailCount   = 0;
        $addressCount = 0;

        // Upsert emails
        foreach ($emails as $email) {
            BlacklistEntry::updateOrCreate(
                ['type' => 'email', 'value' => $email],
                ['source' => 'pgprints']
            );
            $emailCount++;
        }

        // Upsert addresses
        foreach ($addresses as $addr) {
            if (empty($addr)) continue;
            BlacklistEntry::updateOrCreate(
                ['type' => 'address', 'value' => $addr],
                ['source' => 'pgprints']
            );
            $addressCount++;
        }

        $this->info("Imported {$emailCount} emails and {$addressCount} addresses from pgprints.io.");

        return self::SUCCESS;
    }
}
