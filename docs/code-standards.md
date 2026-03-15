# OneShield Code Standards & Architecture

## Codebase Structure

### Gateway Panel (Laravel)

```
gateway-panel/
├── app/
│   ├── Console/
│   │   └── Commands/          # Artisan commands
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/           # API endpoints (HMAC auth)
│   │   │   ├── Panel/         # User dashboard (session auth)
│   │   │   └── SuperAdmin/    # Admin console (admin auth)
│   │   ├── Middleware/        # Auth, tenant isolation
│   │   └── Requests/          # Form validation classes
│   ├── Models/                # Eloquent models
│   ├── Services/              # Business logic
│   └── Exceptions/            # Custom exceptions
├── database/
│   ├── migrations/            # Schema changes
│   ├── factories/             # Model factories (testing)
│   └── seeders/               # Data seeders
├── resources/
│   ├── js/
│   │   ├── Pages/             # Inertia.js pages
│   │   ├── Components/        # Reusable Vue components
│   │   └── Layouts/           # Layout templates
│   └── css/                   # Tailwind CSS
├── routes/
│   ├── api.php                # API routes
│   ├── web.php                # Web routes
│   └── console.php            # Console commands
├── storage/                   # Cache, logs, uploads
└── tests/                     # Test suites
```

### OneShield Connect Plugin (WordPress)

```
plugins/oneshield-connect/
├── oneshield-connect.php      # Main plugin file
├── inc/
│   ├── blacklist.php          # Blacklist checking logic
│   ├── gateway.php            # Payment gateway filter
│   ├── remote.php             # API communication
│   └── helpers.php            # Utility functions
├── assets/
│   ├── js/                    # Frontend scripts
│   └── css/                   # Styles
└── languages/                 # i18n files
```

### OneShield Paygates Plugin (WordPress)

```
plugins/oneshield-paygates/
├── oneshield-paygates.php     # Main plugin file
├── includes/
│   ├── class-os-payment-base.php  # Base payment class
│   └── class-os-gateway.php       # Gateway implementation
├── assets/
├── languages/
└── templates/                 # HTML templates
```

## Naming Conventions

### PHP

**Classes:** PascalCase
```php
class BlacklistService {}
class BlacklistEntry {}
class SystemBlacklistController {}
```

**Methods:** camelCase
```php
public function isBlacklisted() {}
private function normalizeValue() {}
public static function whereOfType() {}
```

**Variables:** camelCase
```php
$userId = auth()->id();
$blacklistAction = $user->blacklist_action;
$trapShieldId = $request->input('trap_shield_id');
```

**Constants:** UPPER_SNAKE_CASE
```php
const MAX_BLACKLIST_ENTRIES = 10000;
const BLACKLIST_CACHE_TTL = 3600;
const HMAC_ALGORITHM = 'sha256';
```

**Database:**
- Tables: snake_case plural (`blacklist_entries`, `shield_sites`)
- Columns: snake_case (`blacklist_action`, `trap_shield_id`)
- Foreign keys: `{table_singular}_id` (`user_id`, `shield_site_id`)
- Timestamps: `created_at`, `updated_at`

### Vue/JavaScript

**Components:** PascalCase
```javascript
import BlacklistEditor from '@/Pages/Blacklist/Editor.vue'
```

**Props/Methods:** camelCase
```javascript
props: { blacklistAction, trapShieldId }
methods: { saveBlacklist, deleteEntry }
```

**Files:** kebab-case or PascalCase per convention
- Pages: `Blacklist/Index.vue` (PascalCase)
- Components: `BlacklistForm.vue` (PascalCase)
- Utility files: `format-blacklist.js` (kebab-case)

### WordPress (PHP)

**Hook names:** snake_case lowercase
```php
apply_filters('osc_blacklist_items', $items)
do_action('osc_before_payment_gateway_filter')
```

**Option names:** osc_ prefix
```php
get_option('osc_blacklist_action')
get_option('osc_trap_shield_id')
```

**Session keys:** osc_ prefix
```php
WC()->session->set('osc_trap_shield_id', 42)
```

## Code Organization

### Service Layer

**Purpose:** Encapsulate business logic separate from HTTP concerns.

**Example: BlacklistService**
```php
namespace App\Services;

class BlacklistService {
    // Static helper: check if fields match blacklist
    public static function isBlacklisted(array $fields): bool {}

    // Normalize address for matching (lowercase, trim, depunctuate)
    public static function normalizeAddress(string $address): string {}
}
```

**Rules:**
- No HTTP request/response in services
- Inject dependencies via constructor
- Use static methods for utilities
- Throw exceptions for error cases

### Model Layer

**Purpose:** Database interaction via Eloquent ORM.

**Example: BlacklistEntry**
```php
namespace App\Models;

class BlacklistEntry extends Model {
    protected $table = 'blacklist_entries';
    protected $fillable = ['type', 'value', 'is_system', 'user_id'];

    // Scopes for querying
    public function scopeOfType(Builder $query, string $type): Builder {}

    // Static helpers for business logic
    public static function isBlacklisted(array $fields): bool {}
}
```

**Rules:**
- Use `$fillable` for mass assignment
- Define `$casts` for type conversion
- Use scopes for common filters
- Keep ORM-specific logic in models

### Controller Layer

**Purpose:** Handle HTTP requests and responses.

**Example: Panel/BlacklistController**
```php
namespace App\Http\Controllers\Panel;

class BlacklistController extends Controller {
    // GET display form
    public function index(): Response {
        // Load current entries
        // Return Inertia response
    }

    // POST handle submission
    public function save(Request $request): RedirectResponse {
        // Validate input
        // Save to database
        // Redirect with message
    }
}
```

**Rules:**
- Keep controllers thin (delegate to services)
- Use Form Requests for validation
- Return Inertia responses (SPA-style)
- Inject dependencies via type hints

### WordPress Plugin Structure

**Purpose:** Modular plugin organization.

**Entry Point: oneshield-connect.php**
```php
<?php
// Load dependencies
require_once plugin_dir_path(__FILE__) . 'inc/blacklist.php';
require_once plugin_dir_path(__FILE__) . 'inc/gateway.php';

// Register hooks at plugin init
add_action('plugins_loaded', function() {
    // Initialize plugin features
});
```

**Module: inc/blacklist.php**
```php
// Define standalone functions for blacklist checking
function osc_get_blacklist() {}
function osc_is_buyer_blacklisted() {}
function osc_normalize_address() {}
```

**Rules:**
- Each feature in separate file
- Use `inc/` directory for included code
- Avoid global state (use WP options/transients)
- Prefix all functions (`osc_`) to avoid conflicts

## Error Handling

### PHP Exceptions

**Custom exceptions for domain errors:**
```php
namespace App\Exceptions;

class BlacklistException extends \Exception {}
class InvalidShieldSiteException extends \Exception {}
```

**Try-catch for external dependencies:**
```php
try {
    $response = Http::get($url);
} catch (ConnectionException $e) {
    Log::error('API unreachable', ['url' => $url]);
    // Fail open or return default
}
```

### WordPress Error Handling

**Fail-open pattern:**
```php
function osc_get_blacklist() {
    try {
        $response = wp_remote_get($url, ['timeout' => 5]);
        if (is_wp_error($response)) {
            return []; // Empty list (fail-open)
        }
        return json_decode($response['body'], true);
    } catch (\Exception $e) {
        error_log('Blacklist fetch failed: ' . $e->getMessage());
        return []; // Empty list
    }
}
```

**Rules:**
- Always fail-open (don't block checkout)
- Log errors for ops review
- Return sensible defaults
- Never expose errors to customer

## Input Validation

### Laravel Form Requests

```php
namespace App\Http\Requests;

class SaveBlacklistRequest extends FormRequest {
    public function rules(): array {
        return [
            'emails'          => 'nullable|string',
            'cities'          => 'nullable|string',
            'blacklist_action' => 'required|in:hide,trap',
            'trap_shield_id'  => 'nullable|integer|exists:shield_sites,id',
        ];
    }
}
```

### Input Normalization

**Always normalize before storage:**
```php
// In controller or service
$value = strtolower(trim($input));

// In model mutator (automatic)
protected function blacklistAction(): Attribute {
    return Attribute::make(
        set: fn ($value) => strtolower($value)
    );
}
```

**Prevent injection:**
- Use parameterized queries (Eloquent ORM does this)
- Validate enum values (in:hide,trap)
- Type-hint numeric IDs (integer|exists)

## Performance Optimization

### Caching Strategy

**Application-level caching:**
```php
// Laravel cache (Redis/file)
$blacklist = Cache::remember('blacklist_' . $userId, 3600, function () {
    return BlacklistEntry::where('user_id', $userId)->get();
});
```

**WordPress transient caching:**
```php
$blacklist = get_transient('osc_blacklist');
if (false === $blacklist) {
    $blacklist = osc_get_blacklist(); // Expensive API call
    set_transient('osc_blacklist', $blacklist, 1 * HOUR_IN_SECONDS);
}
return $blacklist;
```

### Database Indexes

**Critical indexes:**
```sql
-- Blacklist lookups
CREATE INDEX idx_blacklist_type_value ON blacklist_entries(type, value);

-- Tenant isolation
CREATE INDEX idx_blacklist_user_id ON blacklist_entries(user_id);
CREATE INDEX idx_shield_sites_user_id ON shield_sites(user_id);
```

### Query Optimization

**N+1 query prevention:**
```php
// Load all shield sites once
$shields = ShieldSite::where('user_id', $userId)
    ->where('is_active', true)
    ->get();

// Use in-memory filtering
$trapShield = $shields->firstWhere('id', $trapShieldId);
```

**Bulk operations:**
```php
// Delete all entries of type, then bulk insert
BlacklistEntry::where('user_id', $userId)
    ->where('type', 'email')
    ->delete();

BlacklistEntry::insert(
    array_map(fn ($value) => [
        'type' => 'email',
        'value' => $value,
        'user_id' => $userId,
        'is_system' => false,
    ], $values)
);
```

## Testing

### Unit Tests

**Test service logic:**
```php
class BlacklistServiceTest extends TestCase {
    public function test_is_blacklisted_matches_email() {
        // Create blacklist entry
        BlacklistEntry::create(['type' => 'email', 'value' => 'fraud@example.com']);

        // Assert match
        $this->assertTrue(
            BlacklistService::isBlacklisted(['email' => 'fraud@example.com'])
        );
    }
}
```

### Feature Tests

**Test end-to-end flows:**
```php
class BlacklistCheckoutTest extends TestCase {
    public function test_blacklisted_buyer_sees_hidden_gateways() {
        // Create blacklist entry
        // Create WC order with blacklisted email
        // Simulate checkout
        // Assert OneShield gateway not available
    }
}
```

### Coverage Targets

- Business logic: 80%+ coverage
- Controllers: 60%+ coverage
- Models: 80%+ coverage
- Utilities: 90%+ coverage

## Documentation Standards

### Code Comments

**Why, not what:**
```php
// ✓ Good: explains intent
// Clear blacklist entries to prevent duplicate entries
BlacklistEntry::where('is_system', false)
    ->where('user_id', $userId)
    ->where('type', $type)
    ->delete();

// ✗ Bad: restates code
// Delete entries from blacklist
BlacklistEntry::delete();
```

**Complex logic:**
```php
// Trap mode requires a valid shield site; if shield is deleted,
// fall back to first active shield
$shield = ShieldSite::find($trapShieldId)
    ?? ShieldSite::where('is_active', true)->first();
```

### Docblocks

**Methods with complex signatures:**
```php
/**
 * Check if customer fields match any blacklist entry.
 *
 * @param array{email: string, city: string, state: string, zipcode: string} $fields
 * @return bool True if any field matches
 */
public static function isBlacklisted(array $fields): bool {}
```

**Public APIs:**
```php
/**
 * Fetch blacklist entries for authenticated user.
 * Merges customer entries with system entries per configured toggles.
 *
 * GET /api/blacklist
 *
 * @return \Illuminate\Http\JsonResponse {emails[], cities[], states[], zipcodes[], updated_at}
 */
public function index(): JsonResponse {}
```

## Git Commit Standards

### Conventional Commits

```
feat: add blacklist trap shield routing
^--^  ^--------------------------^
|     |
|     +-> description (imperative, lowercase)
+-------> type (feat|fix|docs|refactor|test|chore)
```

**Types:**
- `feat` — New feature
- `fix` — Bug fix
- `docs` — Documentation only
- `refactor` — Code reorganization (no behavior change)
- `test` — Test additions
- `chore` — Build, deps, CI (no code change)

**Examples:**
```
feat: add per-type system blacklist toggles
fix: prevent self-reference in trap shield validation
docs: update blacklist API response format
refactor: extract blacklist checking to service
test: add trap shield routing test
chore: update dependencies
```

### Commit Hygiene

**One logical change per commit**
```bash
# ✓ Good
git commit -m "feat: add blacklist entries support"
git commit -m "feat: add blacklist API endpoint"

# ✗ Bad
git commit -m "feat: add blacklist, update docs, refactor controllers"
```

**Clear, descriptive messages**
```bash
# ✓ Good
git commit -m "fix: validate trap shield ownership in blacklist save"

# ✗ Bad
git commit -m "fix: bug"
```

## Security Checklist

- [ ] Input normalized (lowercase, trimmed)
- [ ] SQL injection prevention (parameterized queries)
- [ ] HMAC signature validation on API routes
- [ ] Tenant isolation checks (user_id scoping)
- [ ] Foreign key validation (trap_shield_id exists)
- [ ] Error messages don't leak sensitive info
- [ ] Secrets not in code (use .env)
- [ ] Fail-open on external API errors
- [ ] Rate limiting on API endpoints
- [ ] Audit logs for sensitive operations

## References

- **Project Overview:** `./docs/project-overview-pdr.md`
- **System Architecture:** `./docs/system-architecture.md`
- **Laravel Documentation:** https://laravel.com/docs
- **Vue 3 Documentation:** https://vuejs.org/guide/
- **WordPress Plugin Handbook:** https://developer.wordpress.org/plugins/
