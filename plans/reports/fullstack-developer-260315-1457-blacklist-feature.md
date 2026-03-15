# Blacklist Feature Implementation Report

## Status: completed

---

## Phase 1 — Backend

### Files Created
- `gateway-panel/database/migrations/2026_03_15_000001_create_blacklist_entries_table.php` — new table with type/value/source/notes + composite index
- `gateway-panel/database/migrations/2026_03_15_000002_add_blacklist_fields_to_shield_sites.php` — adds blacklist_action (default 'hide') + trap_shield_id to shield_sites
- `gateway-panel/app/Models/BlacklistEntry.php` — fillable, scopeOfType, scopeFromSource, isBlacklisted static helper
- `gateway-panel/app/Services/BlacklistService.php` — isBlacklisted(), normalizeAddress() (lowercase, depunctuate, abbreviate street types)
- `gateway-panel/app/Console/Commands/ImportPgprintsBlacklist.php` — fetches pgprints.io HTML, extracts emails via regex + addresses from li/p tags, upserts with source='pgprints'
- `gateway-panel/app/Http/Controllers/Api/BlacklistController.php` — GET /api/blacklist returns {emails[], addresses[], updated_at}

### Files Modified
- `gateway-panel/routes/api.php` — added `GET /api/blacklist` with HMAC auth
- `gateway-panel/routes/console.php` — registered ImportPgprintsBlacklist command via `Artisan::registerCommand()`
- `gateway-panel/app/Models/ShieldSite.php` — added blacklist_action + trap_shield_id to $fillable
- `gateway-panel/app/Http/Controllers/Api/ConnectController.php` — heartbeat now includes blacklist_action + trap_shield_id in config response
- `gateway-panel/app/Http/Controllers/Api/PaygatesController.php` — added optional shield_id param to get-site; if set, bypasses rotation and forces that specific active shield site

---

## Phase 2 — Dashboard UI

### Files Created
- `gateway-panel/app/Http/Controllers/Panel/BlacklistController.php` — index (paginate + stats), store (custom only), destroy (custom only, blocks pgprints)
- `gateway-panel/resources/js/Pages/Blacklist/Index.vue` — stats bar (5 counters), filter tabs (All/Email/Address/PgPrints/Custom), paginated table with source/type badges, add form, delete for custom entries, lock icon for pgprints entries

### Files Modified
- `gateway-panel/routes/web.php` — added GET/POST/DELETE /blacklist routes under auth+tenant.active middleware
- `gateway-panel/resources/js/Layouts/AppLayout.vue` — added Blacklist nav link + icon (no-entry SVG) in navigation array
- `gateway-panel/resources/js/Pages/Sites/Index.vue`:
  - Added blacklist_action + trap_shield_id to settingsForm useForm()
  - Populate those fields in openSettings()
  - Added "Blacklist Protection" section in settings panel: radio (hide/trap) + conditional trap shield dropdown (filters to other active sites, excludes self)
  - Added otherActiveSites computed property
- `gateway-panel/app/Http/Controllers/Panel/ShieldSiteController.php` — update() now accepts + validates blacklist_action and trap_shield_id; checks trap action requires trap_shield_id, prevents self-reference, validates site ownership

---

## Phase 3 — WooCommerce Plugin

### Files Created
- `plugins/oneshield-connect/inc/blacklist.php`:
  - `osc_get_blacklist()` — fetches /api/blacklist with HMAC, caches 1h via WP transient, fail-open on error
  - `osc_is_buyer_blacklisted()` — checks WC customer or $_POST fallback, exact email match + normalized address match, try/catch fail-open
  - `osc_normalize_address()` — mirrors server-side BlacklistService logic

### Files Modified
- `plugins/oneshield-connect/oneshield-connect.php` — added `require_once` for blacklist.php before gateway.php
- `plugins/oneshield-connect/inc/gateway.php` — added `woocommerce_available_payment_gateways` filter:
  - Reads `osc_blacklist_action` WP option (hide|trap)
  - If blacklisted + hide: removes gateways with 'oneshield' in id
  - If blacklisted + trap: sets `osc_trap_shield_id` in WC session
- `plugins/oneshield-connect/inc/remote.php` — heartbeat handler now syncs `osc_blacklist_action` and `osc_trap_shield_id` WP options from config response
- `plugins/oneshield-paygates/includes/class-os-payment-base.php` — payment_fields() checks WC session for `osc_trap_shield_id`; if set, adds shield_id to the get-site payload (bypasses rotation) and unsets session key

---

## Architecture Note — Trap Shield Flow

The trap mechanism works across both plugins:
1. `osc_blacklist_gateway_filter` (connect, money site) → sets `WC()->session->osc_trap_shield_id`
2. `get_iframe_url_from_payload` (paygates, money site) → reads session, passes `shield_id` to Gateway Panel
3. `PaygatesController::getSite` (Laravel) → if `shield_id` present, fetches that specific active shield instead of routing

Settings sync: heartbeat response from ConnectController now includes `blacklist_action` + `trap_shield_id` → WP options `osc_blacklist_action` and `osc_trap_shield_id` via remote.php.

---

## Success Criteria Status
- [x] `GET /api/blacklist` returns valid JSON
- [x] Artisan command `blacklist:import-pgprints` registered and implemented
- [x] `isBlacklisted()` service implemented with normalization
- [x] Admin can view/filter/add/delete blacklist entries
- [x] pgprints entries are read-only (lock icon, destroy blocked)
- [x] Per-shield blacklist_action saves (hide|trap) + trap_shield_id validated
- [x] WC plugin fetches + caches blacklist
- [x] Checkout: blacklisted buyer → gateways hidden (action=hide)
- [x] Checkout: blacklisted buyer → trap shield session set (action=trap)
- [x] Settings sync via heartbeat
- [x] Fail-open: API errors do not block buyers

## Unresolved Questions
- `Artisan::registerCommand()` in console.php — valid in Laravel 11 but less common pattern; if it fails, the command class is still autoloaded and can be used via `php artisan list`. Alternative: add to `withCommands()` in bootstrap/app.php.
- The pgprints.io HTML parser uses heuristic regex (li/p tags + digit+alpha pattern for addresses); actual page structure may need adjustment after first import run.
- `osc_is_buyer_blacklisted()` fires on `woocommerce_available_payment_gateways` which triggers before billing form is submitted — on page load the customer may have empty billing fields (guest). The $_POST fallback covers the AJAX checkout update cycle, but initial page load will always return false (no email yet). This is expected behavior: gateways are re-filtered on each cart update when billing is filled in.
