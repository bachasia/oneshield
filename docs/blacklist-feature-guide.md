# Blacklist Feature Guide

## Overview

The blacklist feature enables OneShield merchants to prevent fraudulent transactions by blocking suspicious customers at WooCommerce checkout. This guide covers setup, usage, API integration, and troubleshooting.

## Table of Contents

1. [For Merchants](#for-merchants)
2. [For Admins](#for-admins)
3. [For Developers](#for-developers)
4. [Architecture](#architecture)
5. [API Reference](#api-reference)
6. [Troubleshooting](#troubleshooting)

---

## For Merchants

### Getting Started

**Location:** Dashboard → Blacklist

**What you can do:**
- Add/remove customer emails and addresses (city, state, zipcode)
- Enable/disable system blacklist entries per type
- Choose protection mode: hide gateways or trap to decoy site
- Select trap shield site (if using trap mode)

### Adding Blacklist Entries

1. Go to **Blacklist** in dashboard
2. Enter customer data in appropriate textarea:
   - **Emails:** One per line (`fraud@example.com`)
   - **Cities:** One per line (`New York`)
   - **States:** Two-letter codes (`NY`)
   - **Zipcodes:** Postal codes (`10001`)
3. Click **Save**

**Tips:**
- Entries are case-insensitive (stored as lowercase)
- Trim whitespace automatically
- Empty lines are ignored
- No special characters needed

### System Blacklist Toggle

**Use System Entries:** Enable per-type toggles to include global blacklist entries in your checkout protection.

**Benefits:**
- Shared fraud database across all merchants
- Managed by OneShield admin team
- Updated automatically
- No additional setup required

**How it works:**
- When enabled: Customers on system list are treated as blacklisted
- When disabled: Only your custom entries are checked
- Each type (email, city, state, zipcode) has separate toggle

### Blacklist Protection Modes

#### Hide Mode (Recommended for Privacy)
**Action:** Remove OneShield payment gateways from checkout

**Flow:**
1. Blacklisted customer lands on checkout
2. OneShield gateways are hidden
3. Customer pays with alternate method (Stripe, PayPal, etc.)
4. No OneShield processing occurs

**Pros:**
- Prevents fraud cleanly
- Customer sees no payment decline
- Reduces false positives impact

**Cons:**
- No record of blocked transaction
- Can't analyze patterns

**Setup:**
1. Go to **Blacklist** page
2. Select radio button: **Hide Mode**
3. Click **Save**

#### Trap Mode (Recommended for Analysis)
**Action:** Route blacklisted customers to a decoy "trap" shield site

**Flow:**
1. Blacklisted customer lands on checkout
2. Select payment method (appears normal)
3. Payment routed to trap shield site
4. Information captured for fraud analysis
5. Transaction may complete or decline at trap site

**Pros:**
- Captures fraud attempt data
- Deceives fraudsters
- Builds pattern recognition database
- Full audit trail

**Cons:**
- Requires active trap shield site
- More complex setup

**Setup:**
1. Create/ensure you have **two shield sites:**
   - Money site (legitimate customers)
   - Trap site (fraud customers)
2. Both must be **Active**
3. Go to **Blacklist** page
4. Select radio button: **Trap Mode**
5. Select trap site from dropdown: **Select Trap Shield Site**
6. Click **Save**

### Verifying Configuration

**Check current settings:**
1. Go to **Blacklist** page
2. Review all fields:
   - Protection mode (hide or trap)
   - Selected trap shield site (if trap mode)
   - System blacklist toggles
   - Custom entries

**Test blacklist in development:**
1. Add test customer email to blacklist
2. Create WC order with that email
3. Proceed to checkout
4. Verify gateway behavior matches mode

---

## For Admins

### System Blacklist Management

**Location:** Admin Panel → System Blacklist

**Purpose:** Manage global blacklist entries shared across all merchants.

**Scope:**
- Affects all merchants with corresponding toggle enabled
- Separate from merchant custom entries
- Updated centrally (no per-merchant configuration)

### Adding System Entries

1. Go to **Admin** → **System Blacklist**
2. Enter data in appropriate textarea:
   - **Emails:** One per line
   - **Cities:** One per line
   - **States:** Two-letter codes
   - **Zipcodes:** Postal codes
3. Click **Save**

**Guidelines:**
- Focus on high-confidence fraud indicators
- Maintain minimal size (performance)
- Review regularly with security team
- Document source of entries

### Monitoring & Maintenance

**Check system blacklist size:**
```sql
SELECT type, COUNT(*) FROM blacklist_entries
WHERE is_system = true
GROUP BY type;
```

**Recent additions:**
```sql
SELECT * FROM blacklist_entries
WHERE is_system = true
ORDER BY created_at DESC
LIMIT 10;
```

**Merchant adoption (toggles enabled):**
```sql
SELECT
  COUNT(CASE WHEN use_system_blacklist_emails THEN 1 END) as email_toggle,
  COUNT(CASE WHEN use_system_blacklist_cities THEN 1 END) as city_toggle,
  COUNT(CASE WHEN use_system_blacklist_states THEN 1 END) as state_toggle,
  COUNT(CASE WHEN use_system_blacklist_zipcodes THEN 1 END) as zipcode_toggle
FROM users;
```

### Best Practices

- **Accuracy First:** Only add high-confidence fraud indicators
- **Minimal Size:** Smaller lists = faster checkout performance
- **Regular Reviews:** Audit quarterly for stale entries
- **Clear Documentation:** Note source of entries (external DB, fraud report, etc.)
- **Communication:** Notify merchants of major changes

---

## For Developers

### Integration Points

#### 1. API Endpoint (GET /api/blacklist)

**Purpose:** Fetch blacklist for WooCommerce plugin

**Authentication:** HMAC-SHA256

**Request:**
```http
GET /api/blacklist HTTP/1.1
Host: gateway.oneshield.io
X-HMAC-Signature: {signature}
X-Timestamp: {unix_timestamp}
```

**Response (200):**
```json
{
  "emails": [
    "fraud1@example.com",
    "fraud2@example.com"
  ],
  "cities": [
    "New York",
    "Los Angeles"
  ],
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

**Caching:** WP transient (1 hour TTL on money site)

#### 2. Panel UI Routes

**User Blacklist Editor:**
- **Route:** `/blacklist`
- **Method:** GET (display), POST (save)
- **Auth:** Session (authenticated users)
- **Response:** Inertia Vue component

**System Blacklist Editor:**
- **Route:** `/admin/system-blacklist`
- **Method:** GET (display), POST (save)
- **Auth:** Admin role
- **Response:** Inertia Vue component

#### 3. WooCommerce Hook Integration

**Hook:** `woocommerce_available_payment_gateways`

**Behavior:**
```php
add_filter('woocommerce_available_payment_gateways', function($gateways) {
    if (osc_is_buyer_blacklisted()) {
        $action = get_option('osc_blacklist_action', 'hide');
        if ($action === 'hide') {
            // Remove OneShield gateways
            unset($gateways['oneshield_gateway']);
        } else if ($action === 'trap') {
            // Set session for trap routing
            WC()->session->set('osc_trap_shield_id', get_option('osc_trap_shield_id'));
        }
    }
    return $gateways;
});
```

### Database Schema

**blacklist_entries:**
```sql
CREATE TABLE blacklist_entries (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  type VARCHAR(20) NOT NULL,           -- 'email', 'city', 'state', 'zipcode'
  value VARCHAR(255) NOT NULL,         -- normalized lowercase
  is_system BOOLEAN NOT NULL DEFAULT 0, -- 0: user, 1: system
  user_id BIGINT,                      -- NULL if is_system=1
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_type_value (type, value),
  INDEX idx_user_id (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);
```

**users (additions):**
```sql
ALTER TABLE users ADD COLUMN (
  blacklist_action VARCHAR(20) DEFAULT 'hide',      -- 'hide' or 'trap'
  trap_shield_id BIGINT,                             -- FK shield_sites.id
  use_system_blacklist_emails BOOLEAN DEFAULT 0,
  use_system_blacklist_cities BOOLEAN DEFAULT 0,
  use_system_blacklist_states BOOLEAN DEFAULT 0,
  use_system_blacklist_zipcodes BOOLEAN DEFAULT 0,

  FOREIGN KEY (trap_shield_id) REFERENCES shield_sites(id)
);
```

### Code Examples

#### Checking if Customer is Blacklisted

```php
use App\Models\BlacklistEntry;

$isBlacklisted = BlacklistEntry::isBlacklisted([
    'email'   => 'customer@example.com',
    'city'    => 'New York',
    'state'   => 'NY',
    'zipcode' => '10001',
]);

if ($isBlacklisted) {
    // Apply protection action
}
```

#### Fetching User's Blacklist (API)

```php
use App\Http\Controllers\Api\BlacklistController;

$controller = new BlacklistController();
$response = $controller->index(); // Returns JSON response
```

#### Adding Entries Programmatically

```php
use App\Models\BlacklistEntry;

BlacklistEntry::create([
    'type'      => 'email',
    'value'     => strtolower(trim('fraud@example.com')),
    'is_system' => false,
    'user_id'   => auth()->id(),
]);
```

#### Bulk Replace Entries

```php
// Delete old entries
BlacklistEntry::where('user_id', $userId)
    ->where('type', 'email')
    ->delete();

// Insert new entries
$emails = ['fraud1@example.com', 'fraud2@example.com'];
foreach ($emails as $email) {
    BlacklistEntry::create([
        'type'      => 'email',
        'value'     => strtolower(trim($email)),
        'is_system' => false,
        'user_id'   => $userId,
    ]);
}
```

### Testing

**Unit Test Example:**
```php
use Tests\TestCase;
use App\Models\BlacklistEntry;
use App\Models\User;

class BlacklistTest extends TestCase
{
    public function test_is_blacklisted_matches_email()
    {
        $user = User::factory()->create();

        BlacklistEntry::create([
            'type'      => 'email',
            'value'     => 'fraud@example.com',
            'is_system' => false,
            'user_id'   => $user->id,
        ]);

        $this->assertTrue(
            BlacklistEntry::isBlacklisted(['email' => 'fraud@example.com'])
        );
    }

    public function test_api_returns_merged_entries()
    {
        $user = User::factory()->create(['use_system_blacklist_emails' => true]);

        // Create custom entry
        BlacklistEntry::create(['type' => 'email', 'value' => 'custom@example.com', 'user_id' => $user->id]);

        // Create system entry
        BlacklistEntry::create(['type' => 'email', 'value' => 'system@example.com', 'is_system' => true]);

        $response = $this->actingAs($user)->getJson('/api/blacklist');

        $this->assertContains('custom@example.com', $response['emails']);
        $this->assertContains('system@example.com', $response['emails']);
    }
}
```

---

## Architecture

### High-level Flow

```
Money Site Checkout
    ↓
[woocommerce_available_payment_gateways filter]
    ├─ Fetch blacklist from API (cached)
    ├─ Check customer email/address
    ├─ Apply protection mode
    ↓
[OneShield gateway displayed or hidden]
    ↓
Customer submits payment
    ↓
[GetSite request to Gateway Panel]
    ├─ Includes shield_id if trap mode
    ├─ HMAC signed
    ↓
[Gateway Panel routing logic]
    ├─ If shield_id: use specified shield
    ├─ Else: rotate among active shields
    ↓
Response: iframe URL (money or trap site)
    ↓
Payment processing
```

### Component Responsibilities

**Gateway Panel:**
- Store blacklist entries (customer + system)
- Expose API for WC plugins
- Provide merchant UI for management
- Provide admin UI for system management

**OneShield Connect (Money Site):**
- Fetch and cache blacklist
- Check customer at checkout
- Apply protection action (hide or set trap session)

**OneShield Paygates (All Sites):**
- Read trap session flag
- Include shield_id in GetSite request
- Process payment on routed site

---

## API Reference

### GET /api/blacklist

**Purpose:** Fetch merged blacklist (customer + system)

**Authentication:** HMAC-SHA256

**Query Parameters:** None

**Request Headers:**
```
X-HMAC-Signature: {sha256_hmac}
X-Timestamp: {unix_timestamp}
```

**Response:**
```json
{
  "emails": ["array of email addresses"],
  "cities": ["array of cities"],
  "states": ["array of state codes"],
  "zipcodes": ["array of postal codes"],
  "updated_at": "ISO8601 timestamp"
}
```

**Status Codes:**
- `200 OK` — Successfully returned blacklist
- `401 Unauthorized` — Invalid HMAC signature
- `500 Internal Server Error` — Database error

**Caching:**
- WP transient: 1 hour TTL
- Fallback on API error: empty list (fail-open)

---

## Troubleshooting

### Issue: Gateways Still Showing for Blacklisted Customer

**Possible Causes:**
1. Blacklist not saved properly
2. Cache hasn't expired (1 hour TTL)
3. API endpoint unreachable

**Diagnosis:**
```php
// Check blacklist entry exists
$exists = BlacklistEntry::where('type', 'email')
    ->where('value', 'customer@example.com')
    ->exists();
echo $exists ? 'Entry exists' : 'Entry not found';

// Check API directly
curl -H "X-HMAC-Signature: ..." https://gateway.oneshield.io/api/blacklist

// Check WP transient cache
echo get_transient('osc_blacklist') ? 'Cached' : 'Not cached';
```

**Solutions:**
1. Save entry again (verify form submit successful)
2. Clear WP cache: `wp transient delete osc_blacklist`
3. Check API connectivity
4. Review CloudFlare/WAF rules if API blocked

### Issue: Trap Shield Not Routing Correctly

**Possible Causes:**
1. Trap shield site not active
2. Trap shield ID validation failed
3. Session lost between requests

**Diagnosis:**
```php
// Check user settings
$user = User::find($userId);
echo "Action: {$user->blacklist_action}\n";
echo "Trap Shield ID: {$user->trap_shield_id}\n";

// Check shield site exists and is active
$shield = ShieldSite::find($user->trap_shield_id);
echo "Shield exists: " . ($shield ? 'yes' : 'no') . "\n";
echo "Shield active: " . ($shield?->is_active ? 'yes' : 'no') . "\n";

// Check GetSite request
error_log('GetSite request: ' . json_encode($payload));
```

**Solutions:**
1. Ensure trap shield site is active (check Sites page)
2. Re-select trap shield on Blacklist page
3. Clear WC session (logout/login customer)
4. Check Paygates plugin logs

### Issue: API Returns Unauthorized (401)

**Possible Causes:**
1. HMAC signature incorrect
2. API credentials wrong
3. Timestamp validation failed

**Diagnosis:**
```php
// Check API credentials
echo get_option('osc_api_key') . "\n";
echo get_option('osc_api_secret') . "\n";

// Verify HMAC calculation
$payload = json_encode(['timestamp' => time()]);
$signature = hash_hmac('sha256', $payload, $secret);
echo "Signature: {$signature}\n";
```

**Solutions:**
1. Regenerate API credentials in panel
2. Verify credentials saved in WP options
3. Check clock sync (timestamp within 5 minutes)
4. Clear API cache: `wp transient delete osc_blacklist`

### Issue: Performance Degradation During Checkout

**Possible Causes:**
1. API response slow
2. Database query slow
3. Blacklist list too large

**Diagnosis:**
```sql
-- Check blacklist size
SELECT COUNT(*) FROM blacklist_entries WHERE is_system = false AND user_id = $userId;

-- Check query performance
EXPLAIN SELECT * FROM blacklist_entries WHERE type = 'email' AND value = ?;

-- Monitor API response time
wp-cli eval 'echo microtime(true);' (before and after API call)
```

**Solutions:**
1. Reduce custom entries (archive old entries)
2. Disable unused system toggles
3. Increase WP transient TTL (not recommended)
4. Check API server load (contact support)

### Issue: System Blacklist Not Applied

**Possible Causes:**
1. Toggle not enabled
2. System entry not saved
3. API not returning system entries

**Diagnosis:**
```php
// Check user toggles
$user = User::find($userId);
echo "Email toggle: " . ($user->use_system_blacklist_emails ? 'on' : 'off') . "\n";
echo "City toggle: " . ($user->use_system_blacklist_cities ? 'on' : 'off') . "\n";

// Check system entries exist
$systemEmails = BlacklistEntry::where('is_system', true)
    ->where('type', 'email')
    ->count();
echo "System emails: {$systemEmails}\n";

// Check API response includes system entries
$response = json_decode(wp_remote_retrieve_body($api_response), true);
echo "API emails: " . json_encode($response['emails']) . "\n";
```

**Solutions:**
1. Enable toggle on Blacklist page
2. Check system blacklist isn't empty
3. Clear WP transient cache
4. Verify API endpoint is returning system entries

---

## References

- **System Architecture:** `./docs/system-architecture.md`
- **Code Standards:** `./docs/code-standards.md`
- **Project Overview:** `./docs/project-overview-pdr.md`
- **Implementation Report:** `/plans/reports/fullstack-developer-260315-1457-blacklist-feature.md`

## Support

- **Issues:** GitHub Issues
- **Questions:** Team Slack #oneshield-dev
- **Documentation:** `/docs/`
