# OneShield Codebase Summary

## Project Overview

OneShield is a multi-tenant fraud prevention platform for WooCommerce merchants. It provides:
- Blacklist-based customer filtering (email, city, state, zipcode)
- Two protection modes: hide (remove gateways) or trap (route to decoy site)
- Per-account and system-level blacklist management
- HMAC-authenticated API for WooCommerce plugin integration

**Key Tech Stack:**
- Backend: Laravel 11 + PostgreSQL
- Frontend: Vue 3 + Inertia.js
- Plugins: WordPress/WooCommerce integration

## Repository Structure

### /gateway-panel
Central management platform (Laravel application).

**Key Directories:**
- `app/Models/BlacklistEntry.php` — Blacklist entry model
- `app/Services/BlacklistService.php` — Blacklist checking logic
- `app/Http/Controllers/Api/BlacklistController.php` — API endpoint
- `app/Http/Controllers/Panel/BlacklistController.php` — User UI
- `app/Http/Controllers/SuperAdmin/SystemBlacklistController.php` — Admin UI
- `database/migrations/2026_03_15_000*.php` — Blacklist schema changes
- `resources/js/Pages/Blacklist/Index.vue` — Blacklist editor UI
- `routes/api.php`, `routes/web.php` — Route definitions

### /plugins/oneshield-connect
WordPress plugin for money site integration.

**Key Files:**
- `oneshield-connect.php` — Plugin entry point
- `inc/blacklist.php` — Blacklist fetching + checking
- `inc/gateway.php` — Payment gateway filter hook
- `inc/remote.php` — Settings sync via heartbeat
- `inc/helpers.php` — Utility functions

### /plugins/oneshield-paygates
WordPress plugin for payment processing (money + trap sites).

**Key Files:**
- `oneshield-paygates.php` — Plugin entry point
- `includes/class-os-payment-base.php` — Payment handling
- `includes/class-os-gateway.php` — Gateway implementation

## Database Schema

### blacklist_entries Table
```
id (PK)
type (enum: email|city|state|zipcode)
value (normalized lowercase)
is_system (boolean)
user_id (FK users.id, nullable if system)
created_at, updated_at
Index: (type, value)
```

**Data Characteristics:**
- All values stored lowercase for case-insensitive matching
- Separate `is_system` flag for global vs per-account entries
- Multiple entries per type per user allowed

### users Table (Additions)
```
blacklist_action (enum: hide|trap, default 'hide')
trap_shield_id (FK shield_sites.id, nullable)
use_system_blacklist_emails (boolean, default false)
use_system_blacklist_cities (boolean, default false)
use_system_blacklist_states (boolean, default false)
use_system_blacklist_zipcodes (boolean, default false)
```

**Data Characteristics:**
- Per-account setting (one configuration per merchant)
- Per-type toggles allow granular control
- trap_shield_id required only if blacklist_action = 'trap'

### shield_sites Table
Used by trap routing to select destination site.

## Core Features

### 1. Blacklist Management

**User Panel (/blacklist)**
- Display current entries grouped by type
- Bulk edit via textarea (newline-separated)
- System toggle checkboxes for per-type inclusion
- Blacklist protection mode selector (hide/trap)
- Trap shield dropdown (filtered to own active sites)
- Save endpoint validates all inputs

**System Admin (/admin/system-blacklist)**
- Same UI as user blacklist
- Manages global system entries (is_system=true)
- Affects all accounts with per-type toggles enabled

**API Endpoint (GET /api/blacklist)**
- HMAC authentication required
- Returns merged customer + system entries
- Per-type system inclusion based on account toggles
- Response format: `{ emails[], cities[], states[], zipcodes[], updated_at }`
- 1-hour WP transient cache on money site

### 2. Blacklist Checking

**WC Integration (oneshield-connect plugin)**
- Hook: `woocommerce_available_payment_gateways`
- Fetches cached blacklist from API
- Checks customer email, billing city/state/postcode
- Applies protection mode:
  - **hide**: Remove OneShield gateways from checkout
  - **trap**: Set session flag for trap routing
- Fail-open: API errors don't block checkout

**Trap Shield Routing (oneshield-paygates plugin)**
- Session flag triggers special GetSite request
- PaygatesController receives shield_id parameter
- Routes payment to specified trap site instead of rotation
- After read, clears session flag

### 3. Settings Synchronization

**Heartbeat Mechanism**
- ConnectController returns blacklist_action + trap_shield_id
- Money site WP heartbeat handler updates local options
- Trap site receives configuration for trap detection

**Flow:**
1. Admin saves settings in panel
2. WC plugin heartbeat → ConnectController
3. Response includes current blacklist_action + trap_shield_id
4. WP updates options (used on next checkout filter)
5. Settings immediately active (no restart needed)

## Key Code Patterns

### Blacklist Entry Management

**Creating entries:**
```php
BlacklistEntry::create([
    'type'      => 'email',
    'value'     => strtolower(trim($input)),
    'is_system' => false,
    'user_id'   => auth()->id(),
]);
```

**Bulk replace (atomic):**
```php
// Delete old entries
BlacklistEntry::where('user_id', $userId)
    ->where('type', 'email')
    ->delete();

// Insert new entries
foreach ($values as $value) {
    BlacklistEntry::create(['type' => 'email', 'value' => $value, ...]);
}
```

**Checking if blacklisted:**
```php
$isBlacklisted = BlacklistEntry::isBlacklisted([
    'email'   => 'customer@example.com',
    'city'    => 'New York',
    'state'   => 'NY',
    'zipcode' => '10001',
]);
```

### API Response Construction

**Merging customer + system entries:**
```php
$emails = BlacklistEntry::where('is_system', false)
    ->where('user_id', $userId)
    ->where('type', 'email')
    ->pluck('value')
    ->all();

if ($user->use_system_blacklist_emails) {
    $emails = array_merge($emails,
        BlacklistEntry::where('is_system', true)
            ->where('type', 'email')
            ->pluck('value')
            ->all()
    );
}

return response()->json(['emails' => array_unique($emails), ...]);
```

### WordPress Caching Pattern

**API call with fallback:**
```php
function osc_get_blacklist() {
    $cached = get_transient('osc_blacklist');
    if ($cached !== false) {
        return $cached;
    }

    try {
        $response = wp_remote_get($endpoint, ['timeout' => 5]);
        if (!is_wp_error($response)) {
            $blacklist = json_decode($response['body'], true);
            set_transient('osc_blacklist', $blacklist, 1 * HOUR_IN_SECONDS);
            return $blacklist;
        }
    } catch (\Exception $e) {
        error_log('Blacklist API failed: ' . $e->getMessage());
    }

    return []; // Fail-open
}
```

## Data Flow Examples

### User Saves Blacklist

```
1. User submits form: /blacklist/save (POST)
2. BlacklistController::save() receives request
3. Validates input (type enum, trap shield exists)
4. Validates trap action requires shield selection
5. Updates user: blacklist_action, trap_shield_id, per-type toggles
6. For each type (email, city, state, zipcode):
   a. Delete all existing entries for this user + type
   b. Parse textarea (split lines, trim, lowercase)
   c. Create new entries
7. Redirect back with success message
8. Next WC heartbeat syncs to money site via ConnectController
```

### Customer Checkout (Not Blacklisted)

```
1. Customer visits money site checkout
2. woocommerce_available_payment_gateways filter fires
3. osc_is_buyer_blacklisted() checks:
   a. Load cached blacklist (1h TTL)
   b. Get customer email, billing address
   c. Normalize each field
   d. Check against blacklist arrays
   e. Return false (not found)
4. OneShield gateways displayed normally
5. Customer submits payment
6. Paygates plugin GetSite request → Gateway Panel
7. PaygatesController routing logic (no shield_id) → money site
8. Payment processes on money site
```

### Customer Checkout (Blacklisted + Trap Mode)

```
1. Customer visits money site checkout
2. woocommerce_available_payment_gateways filter fires
3. osc_is_buyer_blacklisted() returns true
4. blacklist_action = 'trap'
5. Set WC session: osc_trap_shield_id = 42 (trap shield)
6. Customer submits payment
7. Paygates plugin payment_fields():
   a. Check session for trap_shield_id
   b. If set: add shield_id=42 to GetSite payload
   c. Clear session key
8. GetSite request → Gateway Panel with shield_id=42
9. PaygatesController finds shield #42 (trap site)
10. Response: iframe URL for trap site
11. Payment processes on trap site (logged for review)
```

## File Size Summary

**Key files (LOC):**
- `app/Http/Controllers/Api/BlacklistController.php` — ~58 lines
- `app/Http/Controllers/Panel/BlacklistController.php` — ~139 lines
- `app/Http/Controllers/SuperAdmin/SystemBlacklistController.php` — ~78 lines
- `app/Models/BlacklistEntry.php` — ~58 lines
- `app/Services/BlacklistService.php` — ~50 lines (reference only)
- `resources/js/Pages/Blacklist/Index.vue` — ~200+ lines
- `inc/blacklist.php` — ~80 lines
- `inc/gateway.php` — ~40 lines

## Dependencies

**Laravel packages:**
- `laravel/framework` — Core framework
- `inertiajs/inertia-laravel` — SPA routing
- `laravel/tinker` — REPL

**WordPress plugins:**
- WooCommerce — Payment processing framework
- Core WP API — Settings, transients, hooks

**External services:**
- PostgreSQL — Primary database
- Redis (optional) — Cache backend

## Security Model

**Authentication:**
- Session-based for panel UI
- HMAC-SHA256 for API endpoints
- Admin role checks via middleware

**Authorization:**
- Tenant isolation: `user_id` scoping on all queries
- Shield site ownership validation
- Foreign key constraints (database level)

**Input Security:**
- All values normalized (lowercase, trimmed)
- Enum validation (hide|trap)
- Type hints (integer|exists validation)
- No raw SQL queries (Eloquent ORM)

**Fail-Safety:**
- Blacklist API errors don't block checkout
- Empty lists treated as non-blacklisted
- Session-based trap routing (survives request boundary)

## Known Limitations & Future Work

**Current Limitations:**
- Blacklist action is per-account, not per-site (by design)
- Exact matching only (no fuzzy matching or wildcards)
- City/state/zipcode matching is basic (no geolocation)
- No bulk import from external sources

**Future Enhancements:**
- Machine learning fraud scoring
- Batch import from fraud databases
- Custom rule builder UI
- Geographic-based rules
- Pattern detection (velocity checks)
- Webhook notifications
- Advanced reporting & analytics

## References

- **Project Overview & PDR:** `./docs/project-overview-pdr.md`
- **System Architecture:** `./docs/system-architecture.md`
- **Code Standards:** `./docs/code-standards.md`
- **Implementation Report:** `/plans/reports/fullstack-developer-260315-1457-blacklist-feature.md`

## Quick Commands

**Local Development:**
```bash
# Laravel setup
cd gateway-panel
composer install
php artisan migrate
php artisan serve

# Run tests
php artisan test

# Frontend development
npm run dev

# Check blacklist API
curl -H "X-HMAC-Signature: ..." http://localhost:8000/api/blacklist
```

**Production Deployment:**
```bash
# Run migrations
php artisan migrate --force

# Clear caches
php artisan cache:clear
php artisan config:clear

# Restart services
supervisorctl restart oneshield:*
```

**Database Queries:**
```sql
-- Check blacklist entries
SELECT * FROM blacklist_entries WHERE user_id = 1;

-- Check user settings
SELECT blacklist_action, trap_shield_id, use_system_blacklist_*
FROM users WHERE id = 1;

-- List trap shields
SELECT id, name FROM shield_sites
WHERE user_id = 1 AND is_active = true;
```

## Contact & Support

- **Team:** OneShield Platform Team
- **Slack:** #oneshield-dev
- **Issue Tracker:** GitHub Issues
- **Documentation:** `/docs/`
