# Code Review: Blacklist Option C Push Implementation

**Date:** 2026-03-15
**Score: 9/10**
**Verdict: PASS**

---

## Scope

| File | LOC | Role |
|------|-----|------|
| `BlacklistService.php` | 70 | Service extraction — getListForUser |
| `BlacklistController.php` | 31 | Thin controller, delegates to service |
| `ConnectController.php` | 189 | Heartbeat injects blacklist push |
| `remote.php` | 169 | WP-side heartbeat handler, stores to wp_options |
| `blacklist.php` | 170 | WP-side fetch with priority chain |

---

## Overall Assessment

Implementation is correct and coherent end-to-end. The Option C push eliminates the checkout-time HTTP call cleanly. Logic parity between the refactored service and the old inline controller code is confirmed. PHP syntax is valid across all files. No critical bugs found.

---

## Critical Issues

None.

---

## High Priority

**1. `BlacklistEntry::isBlacklisted()` does not scope to user or filter by `is_system` (blacklist.php, line 50)**

The model's `isBlacklisted()` static method (used by `BlacklistService::isBlacklisted()`) queries all rows regardless of `user_id` or `is_system`. This is a pre-existing issue, not introduced by this diff, but worth noting: if this method is called in a multi-tenant context it will match against any tenant's entries. If `isBlacklisted()` is only used server-side for a specific user's checkout flow where the user is already resolved, impact is low. Confirm call sites are tenant-scoped.

**2. `osc_get_blacklist()` wp_options primary branch has no staleness guard (blacklist.php, lines 22-25)**

Once `osc_blacklist_data` is set by heartbeat, it is returned unconditionally forever — there is no TTL or "written_at" timestamp. If a site stops receiving heartbeats (e.g. API key revoked, network partition), the blacklist silently stays stale. The transient fallback is bypassed entirely because `get_option` returns a non-null result.

Mitigation options:
- Store a `pushed_at` timestamp alongside the list and fall through to HTTP fetch if older than N hours.
- Or accept this as intentional fail-open behavior (stale data is better than no checkout).

Document the decision explicitly if intentional.

---

## Medium Priority

**3. `ConnectController::heartbeat()` uses `app(BlacklistService::class)` instead of constructor injection**

`app()` works, but bypasses DI and is harder to test/mock. Since the controller already uses `Request $request` injection, adding `private BlacklistService $blacklistService` to the constructor is consistent with `BlacklistController` and the project's own pattern.

**4. `updated_at` in `BlacklistController::index()` queries globally, not per-user (line 26)**

```php
$latestEntry = BlacklistEntry::latest('updated_at')->first();
```

This returns the latest entry across all tenants. For the heartbeat push this is not used (the field is absent from `getListForUser` return), so no functional impact. But if the `GET /api/blacklist` endpoint is still used by the WP plugin's HTTP fallback, a different tenant's update will make this timestamp misleading. Low risk given the Option C rollout.

**5. Double strtolower in blacklist chain**

`BlacklistService::getListForUser()` returns values as-stored from the DB. `remote.php` applies `strtolower` on store. `osc_get_blacklist()` HTTP fallback applies `strtolower` too. But the wp_options branch returns values exactly as stored (already lowercased by remote.php). The transient branch also returns already-lowercased values. No bug, but the normalization responsibility is split — consider normalizing at DB write time (or in `getListForUser`) and dropping the client-side `strtolower` calls.

---

## Low Priority

**6. `sanitize_text_field` on `blacklist_action` is redundant but harmless** (remote.php, line 107)
The nullish coalesce already provides a fallback string; `sanitize_text_field($config['blacklist_action'] ?? 'hide')` is fine.

**7. `osc_run_heartbeat()` timeout is 10s** (remote.php, line 85)
Reasonable for a background cron task. No issue.

---

## Logic Parity Verification: `getListForUser()` vs Old Inline Code

| Aspect | Old (inline in BlacklistController) | New (BlacklistService::getListForUser) |
|--------|--------------------------------------|----------------------------------------|
| Customer query scope | `is_system=false, user_id=$user->id` | Same |
| System query scope | `is_system=true` (single global $systemQ) | Same (single $systemQ, cloned) |
| Merge condition | Single `use_system_blacklist` flag | Four granular flags per field type |
| array_values + array_unique | Yes | Yes |
| Return shape | `emails, cities, states, zipcodes` | Same |

The refactoring correctly upgraded from a single `use_system_blacklist` boolean to four per-field booleans. The DB migration/model must expose `use_system_blacklist_emails`, `use_system_blacklist_cities`, `use_system_blacklist_states`, `use_system_blacklist_zipcodes` — verify migration exists (not in scope of this review).

---

## Option C Flow Verification

```
Heartbeat (cron) → ConnectController::heartbeat()
  → BlacklistService::getListForUser($user)       ✓ correct user scoped
  → returns { emails, cities, states, zipcodes }  ✓ correct shape

remote.php osc_run_heartbeat()
  → receives $body['blacklist']                   ✓ present in response
  → strtolower normalizes values                  ✓
  → update_option('osc_blacklist_data', $list, false)  ✓ autoload=false correct
  → delete_transient('osc_blacklist')             ✓ bust stale transient

blacklist.php osc_get_blacklist()
  Priority 1: get_option('osc_blacklist_data')    ✓ hits wp_options first
  Priority 2: get_transient('osc_blacklist')      ✓ legacy fallback
  Priority 3: live HTTP to /api/blacklist          ✓ last resort
```

All three tiers are correct. The `is_array()` guards on both store and read paths prevent corrupt data from causing downstream errors.

---

## Positive Observations

- Clean service extraction, DRY — single source of truth for the merge logic.
- `autoload=false` on `update_option` is exactly right for checkout-only data.
- Transient bust after wp_options write prevents stale reads in the fallback branch.
- Fail-open policy (empty array on error) is correct for checkout UX.
- `osc_is_buyer_blacklisted()` covers all three POST data shapes (WC customer, billing_ fields, short-form AJAX keys, post_data serialize) — solid coverage.
- `hash_equals` for key comparison in `register()` — timing-safe, good practice.

---

## Recommended Actions

1. **(High)** Decide and document the staleness policy for `osc_blacklist_data` in wp_options. If intentional fail-open, add a comment. If staleness is a concern, store `pushed_at` with the data.
2. **(Medium)** Move `BlacklistService` injection to `ConnectController` constructor for consistency.
3. **(Low)** Verify DB migration adds the four `use_system_blacklist_*` columns to the users table.
4. **(Low)** Consider centralizing normalization to `getListForUser()` so WP-side can drop the `array_map('strtolower')` calls.

---

## Unresolved Questions

- Is `BlacklistEntry::isBlacklisted()` called outside of a tenant-scoped context anywhere? If so, missing `user_id` scope is a multi-tenant data leak.
- Do the four `use_system_blacklist_*` columns exist in the users migration? Not reviewed here.
- Is `GET /api/blacklist` still an active route or deprecated now that Option C covers the push? If deprecated, the `updated_at` field in `BlacklistController::index()` can be dropped.
