# OneShield System Architecture

## Overview

OneShield is a distributed fraud prevention platform for WooCommerce merchants. It provides transparent payment gateway routing, blacklist-based customer filtering, and decoy ("trap") shield site protection.

**High-level flow:**
```
Money Site Checkout
    ↓
[OneShield Connect Plugin]
    ├─ Blacklist Check (WP transient cache)
    ├─ Protection Mode (hide|trap)
    ├─ Trap Shield Routing (WC session)
    ↓
[OneShield Paygates Plugin]
    ├─ HMAC-signed GetSite request
    ├─ Payment processing
    ↓
[Gateway Panel API]
    ├─ Blacklist validation
    ├─ Shield routing logic
```

## Core Components

### 1. Gateway Panel (Laravel)

**Purpose:** Central management platform for merchants and admins.

**Architecture:**
- **Framework:** Laravel 11 + Inertia.js (React/Vue-style SPA with server-side routing)
- **Auth:** Multi-tenant session + HMAC for API
- **Database:** PostgreSQL

#### Key Services

**BlacklistService** (`app/Services/BlacklistService.php`)
- `isBlacklisted(array $fields): bool` — Check if customer email/address matches any entry
- `normalizeAddress(string $address): string` — Convert to lowercase, trim, depunctuate
- Static helpers for matching logic

**Database Layer**

| Model | Table | Purpose |
|-------|-------|---------|
| `BlacklistEntry` | `blacklist_entries` | All blacklist entries (customer + system) |
| `ShieldSite` | `shield_sites` | Money/trap payment sites |
| `User` | `users` | Merchant accounts |

**Schema:**
```sql
-- blacklist_entries
id, type (enum: email|city|state|zipcode), value (normalized),
is_system (bool), user_id (nullable if system), timestamps

-- users
id, ...,
blacklist_action (enum: hide|trap, default 'hide'),
trap_shield_id (nullable, foreign key to shield_sites),
use_system_blacklist_emails (bool),
use_system_blacklist_cities (bool),
use_system_blacklist_states (bool),
use_system_blacklist_zipcodes (bool)

-- shield_sites
id, user_id, name, domain, is_active, sort_order, ...
```

#### API Endpoints

**GET /api/blacklist** (HMAC auth required)
- Returns merged blacklist for authenticated user
- Merges customer + system entries per configured toggles
- Response:
  ```json
  {
    "emails": ["fraud1@example.com", ...],
    "cities": ["New York", ...],
    "states": ["NY", ...],
    "zipcodes": ["10001", ...],
    "updated_at": "2026-03-15T12:00:00Z"
  }
  ```

**GET/POST /blacklist** (Session auth)
- User blacklist editor
- GET: Display current entries + settings
- POST: Save entries + protection mode + system toggles

**GET/POST /admin/system-blacklist** (Admin auth)
- Superadmin system blacklist editor
- GET: Display system entries
- POST: Save system entries (affects all users with toggles enabled)

#### Controllers

**Panel/BlacklistController**
- `index()` — Display user's blacklist + settings
- `save()` — Validate + save entries + protection mode
- Atomically deletes/recreates entries per type

**SuperAdmin/SystemBlacklistController**
- `index()` — Display system blacklist
- `save()` — Validate + save system entries (is_system=true)

**Api/BlacklistController**
- `index()` — Return merged blacklist JSON
- Handles per-type system toggles
- Returns latest updated_at timestamp

#### Middleware & Security

- **Tenant isolation:** All routes check user_id ownership
- **HMAC auth:** API routes verify `X-HMAC-Signature` header
- **Validation:** trap_shield_id must belong to user (in controller)
- **Input normalization:** Lowercase + trim before storage

### 2. OneShield Connect Plugin (WordPress)

**Purpose:** Payment gateway integration on money site.

**Files:**
- `inc/blacklist.php` — Blacklist checking logic
- `inc/gateway.php` — Payment gateway filtering
- `inc/remote.php` — Heartbeat (settings sync)

#### Blacklist Check Flow

**osc_get_blacklist()** (cached in WP transient)
```
1. Load API credentials (key, secret, endpoint from WP options)
2. GET /api/blacklist (HMAC-signed)
3. Cache response for 1 hour (WP transient)
4. On cache miss: fetch fresh from API
5. On API error: return empty list (fail-open)
```

**osc_is_buyer_blacklisted()**
```
1. Get WC customer object (or $_POST billing data for guest)
2. Extract email, billing_city, billing_state, billing_postcode
3. Normalize each field
4. Check against cached blacklist arrays
5. Return true if any match found
```

#### Gateway Filter Hook

**woocommerce_available_payment_gateways filter**
```
Hook trigger: Cart page loads, billing updated
1. Check if buyer blacklisted
2. Read osc_blacklist_action from WP option (hide|trap)
3. If not blacklisted: pass gateways through
4. If blacklisted + hide: remove OneShield gateways
5. If blacklisted + trap: set WC session osc_trap_shield_id
```

#### Settings Sync (Heartbeat)

**remote.php heartbeat handler**
- Receives config response from ConnectController
- Extracts `blacklist_action` + `trap_shield_id`
- Saves to WP options (updates) and session (for current checkout)

### 3. OneShield Paygates Plugin (WordPress)

**Purpose:** Payment processing on money/trap shield sites.

**Files:**
- `includes/class-os-payment-base.php` — Core payment handling

#### Trap Shield Routing

**get_iframe_url_from_payload()**
```
1. Build payload: site_url, order data, customer data
2. Check WC session for osc_trap_shield_id
3. If set: add shield_id to payload (bypasses rotation)
4. POST to GetSite API with payload + HMAC signature
5. Response contains iframe URL (money or trap shield)
6. Unset session key after read
```

### 4. Data Flow Diagrams

#### Normal Checkout (Not Blacklisted)
```
Customer at money site
    ↓
Select payment → woocommerce_available_payment_gateways fires
    ↓
osc_is_buyer_blacklisted() returns false
    ↓
OneShield gateways displayed normally
    ↓
Customer submits → GetSite request
    ↓
Gateway Panel: no trap_shield_id in payload
    ↓
Response: iframe URL points to money site
    ↓
Payment processed on money site
```

#### Trap Shield Checkout (Blacklisted)
```
Customer at money site
    ↓
Select payment → woocommerce_available_payment_gateways fires
    ↓
osc_is_buyer_blacklisted() returns true
    ↓
blacklist_action = 'trap'
    ↓
Set WC session: osc_trap_shield_id = trap_shield_id
    ↓
OneShield gateways hidden OR shown with warning (depends on flow)
    ↓
Customer submits → GetSite request
    ↓
payment_fields() adds shield_id to payload (from session)
    ↓
Gateway Panel: FindSite(shield_id) returns trap shield
    ↓
Response: iframe URL points to trap shield
    ↓
Payment routes to trap site (captured for review)
```

#### Hide Mode Checkout (Blacklisted)
```
Customer at money site
    ↓
Select payment → woocommerce_available_payment_gateways fires
    ↓
osc_is_buyer_blacklisted() returns true
    ↓
blacklist_action = 'hide'
    ↓
Remove OneShield gateways from available list
    ↓
Customer sees other payment methods (Stripe, PayPal, etc.)
    ↓
No OneShield processing (checkout continues normally)
```

## Database Schema

### blacklist_entries
```sql
CREATE TABLE blacklist_entries (
  id BIGINT PRIMARY KEY,
  type VARCHAR(20) NOT NULL, -- 'email', 'city', 'state', 'zipcode'
  value VARCHAR(255) NOT NULL, -- normalized (lowercase, trimmed)
  is_system BOOLEAN NOT NULL DEFAULT false,
  user_id BIGINT NULLABLE, -- NULL if is_system=true
  created_at TIMESTAMP,
  updated_at TIMESTAMP,

  INDEX (type, value),
  FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### users (additions)
```sql
ALTER TABLE users ADD COLUMN (
  blacklist_action VARCHAR(20) DEFAULT 'hide', -- 'hide' | 'trap'
  trap_shield_id BIGINT NULLABLE,
  use_system_blacklist_emails BOOLEAN DEFAULT false,
  use_system_blacklist_cities BOOLEAN DEFAULT false,
  use_system_blacklist_states BOOLEAN DEFAULT false,
  use_system_blacklist_zipcodes BOOLEAN DEFAULT false,

  FOREIGN KEY (trap_shield_id) REFERENCES shield_sites(id)
);
```

## API Contract

### GET /api/blacklist

**Headers:**
```
Authorization: Bearer {hmac_signature}
X-HMAC-Signature: {hmac_signature}
X-Timestamp: {unix_timestamp}
```

**Response (200):**
```json
{
  "emails": ["fraud1@example.com", "fraud2@example.com"],
  "cities": ["New York", "Los Angeles"],
  "states": ["NY", "CA"],
  "zipcodes": ["10001", "90001"],
  "updated_at": "2026-03-15T15:30:00Z"
}
```

**Response (401):**
```json
{
  "error": "Unauthorized"
}
```

## Configuration

### Gateway Panel (.env)
```
DB_CONNECTION=pgsql
DB_DATABASE=oneshield
DB_USER=postgres
HMAC_SECRET=your_api_secret
```

### Money Site (WP options)
```
osc_api_endpoint = https://gateway.oneshield.io/api
osc_api_key = merchant_key
osc_api_secret = merchant_secret
osc_blacklist_action = hide|trap
osc_trap_shield_id = 42
```

### Trap Shield Site (WP options)
- Same as money site (routes to itself for trapping)

## Performance Considerations

### Caching

**WP Transient (1 hour TTL)**
```php
$blacklist = get_transient('osc_blacklist');
if (!$blacklist) {
  $blacklist = osc_get_blacklist(); // API call
  set_transient('osc_blacklist', $blacklist, 1 * HOUR_IN_SECONDS);
}
```

### Database Indexes
- `blacklist_entries(type, value)` — Fast type + value lookups
- `users(id)` — Tenant isolation scopes

### Query Optimization
- Blacklist API uses `pluck()` for memory efficiency
- Panel controller uses cloned queries (avoid re-executing)
- Shield site queries filtered by is_active

## Security Considerations

### HMAC Authentication
- Each merchant account has unique API key + secret
- Requests signed with HMAC-SHA256
- Prevents unauthorized API access

### Tenant Isolation
- All queries scoped by `user_id` (current account)
- Shield site validation: trap_shield_id must belong to user
- Middleware enforces auth on protected routes

### Input Normalization
- All values normalized to lowercase before storage/comparison
- Prevents case-sensitive injection attacks
- Trim + filter empty values in bulk operations

### Fail-Safe Defaults
- API errors don't block checkout (fail-open)
- Missing customer data (email, address) treated as non-blacklisted
- Empty blacklist lists allowed (permissive by default)

## Failure Modes

### API Unavailable
- WP transient returns stale blacklist (up to 1 hour old)
- If no cache: return empty list (fail-open)
- Checkout continues, no error shown to customer

### Database Error
- Graceful degradation (return empty blacklist)
- Logs error for ops review
- User sees no change in gateway display

### Invalid Trap Shield
- Controller validation prevents saving invalid shield_id
- If somehow invalid: payment routes to random active shield (fallback)

### Missing Settings
- blacklist_action defaults to 'hide'
- trap_shield_id nullable (trap mode requires selection)
- Per-type toggles default to false (opt-in model)

## Monitoring & Observability

### Key Metrics
- API response time (P95, P99)
- Blacklist cache hit rate
- Trap shield routing success rate
- API error rate

### Logs
- API endpoint logs all requests (key ID, IP, response code)
- Panel controller logs blacklist saves (user, changes)
- WC plugin logs blacklist checks (email, matched entry)

### Alerts
- API error rate > 5% → page ops
- Response time > 500ms → investigate cache
- Trap shield routing failures → manual review

## Deployment

### Gateway Panel
```bash
php artisan migrate --env=production
php artisan cache:clear
# Deploy Laravel code
supervisorctl restart oneshield:*
```

### WC Plugins
```bash
# Update via WP admin or direct file copy
# Verify settings in WP options
# Test checkout flow
```

### Verification Checklist
- [x] Blacklist API responds with valid JSON
- [x] Panel UI saves/loads entries correctly
- [x] WC plugin fetches blacklist without errors
- [x] Trap routing works end-to-end
- [x] Settings sync via heartbeat
- [x] Fail-open on API errors

## Related Documentation

- **Project Overview:** `./docs/project-overview-pdr.md`
- **Code Standards:** `./docs/code-standards.md`
- **Implementation Report:** `/plans/reports/fullstack-developer-260315-1457-blacklist-feature.md`
