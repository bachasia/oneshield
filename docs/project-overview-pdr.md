# OneShield Project Overview & Product Development Requirements

## Project Vision

OneShield is a comprehensive payment gateway protection platform designed for WooCommerce store owners. It enables merchants to:
- Route suspicious or fraudulent transactions to decoy shield sites
- Filter transactions based on customer blacklist criteria
- Manage multi-site WooCommerce deployments with unified payment processing
- Protect revenue from fraud while maintaining user experience

## Architecture

### System Components

**Gateway Panel** (Laravel + Vue 3 + Inertia.js)
- Multi-tenant management application
- Dashboard for shield site configuration
- Blacklist management interface
- Admin controls for system-wide settings

**OneShield Connect Plugin** (WordPress)
- WooCommerce integration plugin
- Payment gateway filtering and routing
- Blacklist checking at checkout
- HMAC-authenticated communication with Gateway Panel

**OneShield Paygates Plugin** (WordPress)
- Decoy payment gateway implementation
- Trap shield handling
- Secure payment routing based on customer verification

### Data Flow Architecture

```
Customer Checkout (Money Site)
    ↓
WC Available Payment Gateways Filter
    ↓
[BlacklistCheck]
    ├─ If blacklisted + action=hide → Remove OneShield gateways
    └─ If blacklisted + action=trap → Route to trap shield site
    ↓
OneShield Connect Gateway Display
    ↓
Customer Submits Payment
    ↓
GetSite Request (HMAC-signed) → Gateway Panel API
    ↓
[RateLimitCheck] [BlacklistCheck]
    ↓
Response: iframe URL (money/trap shield)
    ↓
Payment Processing on Selected Shield Site
```

## Core Features

### 1. Blacklist Management (v1.0 - Complete)

**Purpose:** Prevent fraudulent transactions by blocking suspicious customers at checkout.

**Scope:**
- Per-account blacklist entries (email, city, state, zipcode)
- System-level blacklist managed by superadmin
- Per-type system blacklist toggles (use_system_blacklist_emails, cities, states, zipcodes)
- Two protection modes: hide (remove gateway) or trap (route to decoy site)

**Key Entities:**

| Table | Purpose | Fields |
|-------|---------|--------|
| blacklist_entries | All blacklist data | id, type (email\|city\|state\|zipcode), value, is_system (bool), user_id, timestamps |
| users | Account config | blacklist_action (hide\|trap), trap_shield_id, use_system_blacklist_emails/cities/states/zipcodes |
| shield_sites | Payment sites | name, domain, is_active, user_id, etc. |

**API Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | /api/blacklist | HMAC | Fetch merged blacklist for WC plugin |
| GET | /blacklist | Session | Display blacklist editor |
| POST | /blacklist/save | Session | Save account blacklist & protection settings |
| GET | /admin/system-blacklist | Admin | Display system blacklist editor |
| POST | /admin/system-blacklist/save | Admin | Save system-level blacklist |

**UI Pages:**
- `/blacklist` — Account blacklist manager with 4 textarea fields (email, city, state, zipcode), protection mode toggle, trap shield selector, per-type system toggles
- `/admin/system-blacklist` — Superadmin system blacklist editor

### 2. Shield Site Management

**Purpose:** Configure and manage payment sites (money and trap shield sites).

**Features:**
- Create/edit active and inactive shield sites
- Set primary vs decoy sites
- Assign trap shield for blacklist trap action
- Sort priority for payment gateway rotation

**UI Pages:**
- `/sites` — List, create, edit shield sites
- Settings modal includes blacklist protection configuration

### 3. Payment Gateway Routing

**Purpose:** Dynamically route customers to appropriate payment sites based on risk.

**Flow:**
1. Customer selects payment method at money site checkout
2. Blacklist check runs (via `woocommerce_available_payment_gateways` hook)
3. If not blacklisted → OneShield gateway displays normally
4. Customer submits payment → HMAC-signed GetSite request
5. Response includes iframe URL pointing to money site or trap shield
6. Payment processes on routed site

### 4. Multi-Tenant Architecture

**Purpose:** Enable single platform to serve unlimited merchants.

**Key Properties:**
- Each user (merchant account) isolated by user_id
- Blacklist entries scoped by user_id
- Shield sites owned by user_id
- API auth via HMAC (key + secret tied to account)
- Middleware enforces tenant isolation on all routes

## Product Requirements

### Functional Requirements

#### FR-BL-1: Blacklist Entry Management
- [x] Users can add/remove email, city, state, zipcode entries
- [x] Entries stored normalized (lowercase, trimmed)
- [x] Entries validated by type
- [x] Support bulk paste (textarea with newline separation)

#### FR-BL-2: System Blacklist
- [x] Superadmin can manage global blacklist entries
- [x] Separate from user-specific entries
- [x] Per-type toggles allow users to opt-in to system lists

#### FR-BL-3: Blacklist Protection Modes
- [x] **hide** — Remove OneShield gateways from checkout
- [x] **trap** — Route to configured trap shield site
- [x] Per-account setting (global to all sites)
- [x] Trap requires valid trap_shield_id

#### FR-BL-4: Blacklist Checking at Checkout
- [x] WC plugin fetches blacklist from API on each payment attempt
- [x] Check runs against email, billing city, billing state, billing zipcode
- [x] Apply protection mode (hide or trap)
- [x] Fail-open: API errors do not block checkout

#### FR-BL-5: API Integration
- [x] GET /api/blacklist returns merged customer + system entries
- [x] HMAC authentication
- [x] Cache for performance (1 hour TTL)
- [x] Return format: { emails[], cities[], states[], zipcodes[], updated_at }

### Non-Functional Requirements

#### NR-SEC-1: Security
- [x] HMAC signing for API auth
- [x] Tenant isolation via middleware
- [x] Normalized input (lowercase) prevents injection
- [x] Shield site ownership validation (can't set trap shield from other account)

#### NR-PERF-1: Performance
- [x] Blacklist API cached (1 hour WP transient)
- [x] Composite index on (type, value) for fast lookups
- [x] Transactional bulk updates in controller

#### NR-REL-1: Reliability
- [x] Fail-open on API errors (checkout not blocked)
- [x] Graceful handling of missing customer data (email optional)
- [x] Clear error messages in admin UI

#### NR-OPS-1: Operability
- [x] Superadmin can manage global lists without affecting accounts
- [x] Per-type toggles allow granular control
- [x] Clear UI indication of system vs custom entries
- [x] Audit trail via timestamps

## Implementation Status

### Completed (Mar 15, 2026)
- [x] Database schema (blacklist_entries, users columns)
- [x] Models (BlacklistEntry)
- [x] Services (BlacklistService for checking)
- [x] API endpoint (GET /api/blacklist, HMAC auth)
- [x] Panel UI (/blacklist page with all controls)
- [x] SuperAdmin UI (/admin/system-blacklist)
- [x] WC Plugin integration (blacklist checking, trap routing)
- [x] Heartbeat sync (settings propagation)

### Known Limitations
- Blacklist action is per-account, not per-site (by design — simplifies UX)
- Trap shield must be active (validates in controller)
- Blacklist check only happens on gateway filter and payment submission
- City/state/zipcode matching is exact (case-insensitive) — no fuzzy matching

### Testing Status
- API endpoint tested (returns valid JSON)
- Panel UI tested (save/load works)
- WC plugin tested (blacklist checking, trap routing)
- Settings sync tested (heartbeat includes new fields)

## Success Metrics

| Metric | Target | Status |
|--------|--------|--------|
| API response time | <100ms | ✓ (cached) |
| Checkout impact | No blocking on API error | ✓ (fail-open) |
| False positive rate | <1% (manual review) | TBD (production) |
| System uptime | 99.9% | TBD (production) |

## Roadmap

### Phase 1 (Complete)
- Blacklist feature (entries, API, UI, WC integration)

### Phase 2 (Planned)
- Fraud scoring model (risk assessment beyond blacklist)
- Transaction logging & reporting
- Webhook notifications (new fraud detected)
- Batch import from external sources

### Phase 3 (Planned)
- Machine learning for pattern detection
- Geolocation-based rules
- Custom rule builder UI

## Technology Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 11, PHP 8.3 |
| Frontend | Vue 3 + Inertia.js |
| Database | PostgreSQL |
| Auth | HMAC, Session middleware |
| Plugins | WordPress + WooCommerce |

## Team

- **Product Owner:** OneShield Platform Team
- **Development:** Full-stack team
- **QA:** Internal testing + production monitoring

## References

- Blacklist Feature Implementation Report: `/plans/reports/fullstack-developer-260315-1457-blacklist-feature.md`
- Blacklist Feature Plan: `/plans/260315-1448-blacklist-feature/`
- Code Standards: `./docs/code-standards.md`
- System Architecture: `./docs/system-architecture.md`
