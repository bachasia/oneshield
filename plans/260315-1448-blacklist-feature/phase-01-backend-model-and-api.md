# Phase 1: Backend — Model, Migration, Seeder, API

## Overview
- Priority: High
- Status: complete
- Goal: Store blacklist entries in DB, one-time import from pgprints.io, serve via API to WC plugin

## Related Code Files

**Create:**
- `gateway-panel/app/Models/BlacklistEntry.php`
- `gateway-panel/database/migrations/XXXX_create_blacklist_entries_table.php`
- `gateway-panel/database/seeders/PgprintsBlacklistSeeder.php`
- `gateway-panel/app/Console/Commands/ImportPgprintsBlacklist.php`
- `gateway-panel/app/Http/Controllers/Api/BlacklistController.php`
- `gateway-panel/app/Services/BlacklistService.php`

**Modify:**
- `gateway-panel/routes/api.php` — add blacklist endpoint
- `gateway-panel/app/Models/ShieldSite.php` — add `blacklist_action`, `trap_shield_id` fields
- `gateway-panel/database/migrations/` — alter shield_sites table

## Implementation Steps

### 1. Migration: `blacklist_entries`
```php
Schema::create('blacklist_entries', function (Blueprint $table) {
    $table->id();
    $table->string('type'); // 'email' | 'address'
    $table->string('value');  // normalized lowercase
    $table->string('source')->default('pgprints'); // 'pgprints' | 'custom'
    $table->string('notes')->nullable();
    $table->timestamps();
    $table->index(['type', 'value']);
});
```

### 2. Migration: alter `shield_sites`
```php
$table->string('blacklist_action')->default('hide'); // 'hide' | 'trap'
$table->unsignedBigInteger('trap_shield_id')->nullable()->index();
```

### 3. Model: `BlacklistEntry`
- Fillable: `type`, `value`, `source`, `notes`
- Scope: `ofType($type)`, `fromSource($source)`
- Static method: `isBlacklisted(string $email, string $address): bool`

### 4. Artisan command: `blacklist:import-pgprints`
- Fetch `https://pgprints.io/test-buy-address/` (HTTP GET)
- Parse HTML — extract email list + address list
- Normalize: lowercase, trim
- Upsert into `blacklist_entries` with `source = 'pgprints'`
- Output: count imported
- **Run once** during deployment: `php artisan blacklist:import-pgprints`

### 5. BlacklistService
```php
// Check if order info matches blacklist
public function isBlacklisted(string $email, string $address): bool

// Normalize address for comparison
private function normalizeAddress(string $addr): string
// → lowercase, trim, replace "street"→"st", "avenue"→"ave", remove punctuation
```

### 6. API endpoint: `GET /api/blacklist`
- Auth: existing HMAC middleware (same as other API routes)
- Returns: `{ emails: [], addresses: [], updated_at: "" }`
- WC plugin caches this response for 1 hour via WP transients

## Todo
- [x] Create migration `blacklist_entries`
- [x] Create migration alter `shield_sites` (blacklist_action, trap_shield_id)
- [x] Create `BlacklistEntry` model
- [x] Create `BlacklistService`
- [x] Create artisan command `ImportPgprintsBlacklist`
- [x] Create `BlacklistController` with `index()` method
- [x] Register route `GET /api/blacklist` in `api.php`
- [x] Run `php artisan blacklist:import-pgprints` once (command created then deleted, pgprints integration removed)
- [x] Write basic test for `BlacklistService::isBlacklisted()`

## Implementation Notes
- Migration blacklist_entries created with type, value, source, is_system, user_id fields
- Migration alter shield_sites: added blacklist_action, trap_shield_id (later moved to users table)
- BlacklistEntry model + BlacklistService implemented with isBlacklisted() logic
- BlacklistController::index() returns paginated entries
- API route GET /api/blacklist registered with HMAC auth
- Additional migrations applied: is_system+user_id to blacklist_entries; moved blacklist_action/trap_shield_id from shield_sites to users table
- Replaced use_system_blacklist with per-type booleans: use_system_blacklist_emails, use_system_blacklist_cities, use_system_blacklist_states, use_system_blacklist_zipcodes
- pgprints import command created then deleted (pgprints integration removed entirely)

## Success Criteria
- `GET /api/blacklist` returns valid JSON with pgprints entries
- Artisan command imports without error
- `isBlacklisted()` returns correct result for known entries
