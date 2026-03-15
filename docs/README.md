# OneShield Documentation

Welcome to the OneShield project documentation. This directory contains comprehensive guides for merchants, administrators, developers, and operations teams.

## Documentation Index

### Getting Started

**[Project Overview & Product Development Requirements](./project-overview-pdr.md)**
- Project vision and high-level architecture
- Core features and components
- Blacklist feature scope and requirements
- Success metrics and roadmap

**[Codebase Summary](./codebase-summary.md)**
- Repository structure and key files
- Database schema overview
- Core features and data flows
- Technology stack and dependencies

### Feature Documentation

**[Blacklist Feature Guide](./blacklist-feature-guide.md)**
- For merchants: How to use blacklist feature
- For admins: System blacklist management
- For developers: API integration and code examples
- Testing and troubleshooting guide

### Technical Documentation

**[System Architecture](./system-architecture.md)**
- High-level system design
- Component interactions and data flows
- API contracts and specifications
- Database schema details
- Performance and security considerations
- Deployment checklist

**[Code Standards](./code-standards.md)**
- Codebase structure and organization
- Naming conventions (PHP, Vue, WordPress)
- Code organization patterns
- Error handling strategies
- Input validation and security
- Performance optimization techniques
- Testing standards
- Git commit guidelines

## Quick Navigation by Role

### For Merchants
**Want to use OneShield?**
1. Read: [Blacklist Feature Guide - For Merchants](./blacklist-feature-guide.md#for-merchants)
2. Setup: Add blacklist entries and choose protection mode
3. Test: Create test orders to verify configuration

### For Administrators
**Managing the platform?**
1. Read: [Blacklist Feature Guide - For Admins](./blacklist-feature-guide.md#for-admins)
2. Read: [System Architecture - Failure Modes](./system-architecture.md#failure-modes)
3. Use: System Blacklist page to manage global entries
4. Monitor: Check metrics and logs regularly

### For Developers
**Contributing to OneShield?**
1. Read: [Codebase Summary](./codebase-summary.md)
2. Read: [Code Standards](./code-standards.md)
3. Read: [System Architecture - API Contract](./system-architecture.md#api-contract)
4. Reference: [Blacklist Feature Guide - For Developers](./blacklist-feature-guide.md#for-developers)

### For Operations
**Deploying and monitoring?**
1. Read: [System Architecture - Deployment](./system-architecture.md#deployment)
2. Read: [System Architecture - Monitoring](./system-architecture.md#monitoring--observability)
3. Read: [Blacklist Feature Guide - Troubleshooting](./blacklist-feature-guide.md#troubleshooting)
4. Reference: Quick commands in [Codebase Summary](./codebase-summary.md#quick-commands)

---

## Key Concepts

### Blacklist Feature (v1.0 - Complete)

The core feature that prevents fraudulent transactions by blocking suspicious customers at checkout.

**Key Components:**
- **Database:** `blacklist_entries` table stores customer and system entries
- **API:** `GET /api/blacklist` returns merged lists for WooCommerce plugins
- **UI:** Merchant dashboard for managing entries and settings
- **Admin:** SuperAdmin interface for system-level blacklist management
- **Integration:** WooCommerce hooks for checkout filtering and trap routing

**Protection Modes:**
- **Hide:** Remove OneShield gateways from checkout
- **Trap:** Route blacklisted customers to decoy "trap" shield site

**System Toggles:**
- Per-type toggles allow merchants to opt-in to global system blacklist
- Types: emails, cities, states, zipcodes

See: [Blacklist Feature Guide](./blacklist-feature-guide.md)

### Multi-Tenant Architecture

Single platform serves unlimited merchants with complete data isolation.

**Key Properties:**
- Each user (merchant) is separate tenant
- All data scoped by `user_id`
- Independent blacklist entries per merchant
- Shared system blacklist (optional per-merchant)
- Per-account protection settings

See: [System Architecture - Architecture](./system-architecture.md#overview)

### API Authentication (HMAC)

Secure communication between WooCommerce plugins and Gateway Panel.

**Mechanism:**
- Each merchant account has unique API key + secret
- Requests signed with HMAC-SHA256
- Server validates signature before responding
- Prevents unauthorized access

See: [System Architecture - API Contract](./system-architecture.md#api-contract)

### Shield Site Routing

Dynamic payment processing routing based on fraud assessment.

**Flow:**
1. Customer selects payment method
2. Blacklist check determines if fraudulent
3. If not fraudulent: route to money site (normal processing)
4. If fraudulent + trap mode: route to trap shield site (capture for analysis)

See: [System Architecture - Data Flow Diagrams](./system-architecture.md#4-data-flow-diagrams)

---

## Feature Checklist

### Blacklist Feature (Mar 15, 2026)

#### Database & Models
- [x] `blacklist_entries` table with type/value/is_system/user_id
- [x] `users` table additions: blacklist_action, trap_shield_id, per-type toggles
- [x] `BlacklistEntry` model with scopes and helpers
- [x] `BlacklistService` for checking logic

#### API
- [x] `GET /api/blacklist` endpoint with HMAC auth
- [x] Merged customer + system entries per toggles
- [x] Return format: { emails[], cities[], states[], zipcodes[], updated_at }
- [x] WP transient caching (1 hour TTL)
- [x] Fail-open on API errors

#### Panel UI
- [x] `/blacklist` page with 4 textarea fields (email, city, state, zipcode)
- [x] Bulk entry editor with newline separation
- [x] Per-type system blacklist toggles
- [x] Blacklist protection mode selector (hide/trap)
- [x] Trap shield dropdown (filtered to user's active sites)
- [x] Settings save and validation

#### Admin UI
- [x] `/admin/system-blacklist` page
- [x] Global blacklist entry management
- [x] Same interface as merchant blacklist
- [x] Affects all merchants with toggles enabled

#### WooCommerce Plugin
- [x] Blacklist check at checkout (`woocommerce_available_payment_gateways` hook)
- [x] API integration with HMAC signing
- [x] Transient caching for performance
- [x] Hide mode: remove gateways
- [x] Trap mode: set session flag for trap routing
- [x] Fail-open: API errors don't block checkout
- [x] Settings sync via heartbeat

#### Trap Shield Routing
- [x] Session flag triggers special GetSite request
- [x] Paygates plugin reads trap shield ID from session
- [x] GetSite request includes shield_id parameter
- [x] Gateway Panel routes to specified trap shield
- [x] Session cleared after read

#### Security
- [x] Tenant isolation (user_id scoping)
- [x] HMAC authentication for API
- [x] Shield site ownership validation
- [x] Input normalization (lowercase, trim)
- [x] Enum validation for protection mode
- [x] Foreign key constraints

#### Testing
- [x] API endpoint returns valid JSON
- [x] Panel UI saves/loads correctly
- [x] WC plugin fetches blacklist
- [x] Trap routing works end-to-end
- [x] Settings sync via heartbeat
- [x] Fail-open on API errors

---

## Recent Changes

### Mar 15, 2026 - Blacklist Feature Complete

**Major Changes:**
- Implemented complete blacklist feature (entries, API, UI, WC integration)
- Database schema finalized: `blacklist_entries` table, `users` columns
- API endpoint: `GET /api/blacklist` with HMAC auth
- Panel UI: `/blacklist` page with all controls
- Admin UI: `/admin/system-blacklist` for system-level management
- WC plugin integration: blacklist checking and trap routing
- Settings sync: heartbeat includes blacklist_action and trap_shield_id

**Files Created:**
- `gateway-panel/app/Models/BlacklistEntry.php`
- `gateway-panel/app/Services/BlacklistService.php`
- `gateway-panel/app/Http/Controllers/Api/BlacklistController.php`
- `gateway-panel/app/Http/Controllers/Panel/BlacklistController.php`
- `gateway-panel/app/Http/Controllers/SuperAdmin/SystemBlacklistController.php`
- `gateway-panel/resources/js/Pages/Blacklist/Index.vue`
- `plugins/oneshield-connect/inc/blacklist.php`
- Multiple database migrations

**Files Modified:**
- `routes/api.php` - Added blacklist API route
- `routes/web.php` - Added blacklist UI routes
- Various models and controllers for integration

See: [Implementation Report](/plans/reports/fullstack-developer-260315-1457-blacklist-feature.md)

---

## Documentation Structure

```
docs/
├── README.md                           # This file
├── project-overview-pdr.md             # Project vision, requirements, roadmap
├── codebase-summary.md                 # Code structure, quick reference
├── system-architecture.md              # Technical design, APIs, data flows
├── code-standards.md                   # Naming, patterns, best practices
└── blacklist-feature-guide.md          # Feature usage, integration, troubleshooting
```

**Typical Size:** Each file ~200-400 lines, focused and cross-linked.

---

## How to Maintain Documentation

### When Adding Features
1. Update [Project Overview](./project-overview-pdr.md) with requirements
2. Add architecture details to [System Architecture](./system-architecture.md)
3. Document code patterns in [Code Standards](./code-standards.md)
4. Update feature summary in [Codebase Summary](./codebase-summary.md)
5. Create/update feature guide if user-facing

### When Fixing Bugs
1. Document root cause in relevant architecture docs
2. Update [Code Standards](./code-standards.md) if pattern-related
3. Add test examples to feature guides

### When Refactoring Code
1. Update [Code Standards](./code-standards.md) naming/patterns if changed
2. Update [System Architecture](./system-architecture.md) component interactions if affected
3. Update [Codebase Summary](./codebase-summary.md) code patterns

### When Deploying
1. Review [System Architecture - Deployment](./system-architecture.md#deployment) checklist
2. Verify [Code Standards - Security Checklist](./code-standards.md#security-checklist)
3. Update deployment notes with any new requirements

---

## Related Documentation

### Project Root
- [README.md](/README.md) - Project setup and overview
- [LOCAL-DEV-SETUP.md](/LOCAL-DEV-SETUP.md) - Development environment
- [DEPLOY.md](/DEPLOY.md) - Deployment guide
- [oneshield-plan.md](/oneshield-plan.md) - Original project plan

### Implementation Plans
- [Blacklist Feature Plan](/plans/260315-1448-blacklist-feature/) - Feature phases and details
- [Implementation Report](/plans/reports/fullstack-developer-260315-1457-blacklist-feature.md) - Completion status

---

## Questions & Support

### Common Questions

**Q: Where do I add customer blacklist entries?**
A: See [Blacklist Feature Guide - Adding Entries](./blacklist-feature-guide.md#adding-blacklist-entries)

**Q: How does trap shield routing work?**
A: See [System Architecture - Data Flow Diagrams](./system-architecture.md#4-data-flow-diagrams)

**Q: What's the API response format?**
A: See [Blacklist Feature Guide - API Reference](./blacklist-feature-guide.md#api-reference)

**Q: How do I test the blacklist feature?**
A: See [Blacklist Feature Guide - Testing](./blacklist-feature-guide.md#testing)

**Q: Why is my trap shield not working?**
A: See [Blacklist Feature Guide - Troubleshooting](./blacklist-feature-guide.md#issue-trap-shield-not-routing-correctly)

### Get Help

- **Documentation:** Start with [Quick Navigation by Role](#quick-navigation-by-role)
- **Troubleshooting:** See [Blacklist Feature Guide - Troubleshooting](./blacklist-feature-guide.md#troubleshooting)
- **Code Examples:** Check [Blacklist Feature Guide - For Developers](./blacklist-feature-guide.md#for-developers)
- **Architecture Details:** Review [System Architecture](./system-architecture.md)
- **Standards & Patterns:** Read [Code Standards](./code-standards.md)

---

## Document Versions

| Document | Last Updated | Status |
|----------|--------------|--------|
| project-overview-pdr.md | Mar 15, 2026 | Complete |
| codebase-summary.md | Mar 15, 2026 | Complete |
| system-architecture.md | Mar 15, 2026 | Complete |
| code-standards.md | Mar 15, 2026 | Complete |
| blacklist-feature-guide.md | Mar 15, 2026 | Complete |

---

## Quick Reference

### Key Endpoints

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/api/blacklist` | Fetch blacklist for WC plugin |
| GET | `/blacklist` | Display merchant blacklist editor |
| POST | `/blacklist/save` | Save merchant blacklist entries |
| GET | `/admin/system-blacklist` | Display system blacklist editor |
| POST | `/admin/system-blacklist/save` | Save system blacklist entries |

### Database Tables

| Table | Purpose | Key Columns |
|-------|---------|------------|
| `blacklist_entries` | All blacklist data | id, type, value, is_system, user_id |
| `users` | Account settings | blacklist_action, trap_shield_id, use_system_blacklist_* |
| `shield_sites` | Payment sites | user_id, is_active, name, domain |

### Key Files

| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/BlacklistController.php` | API endpoint |
| `app/Http/Controllers/Panel/BlacklistController.php` | Merchant UI |
| `app/Http/Controllers/SuperAdmin/SystemBlacklistController.php` | Admin UI |
| `app/Models/BlacklistEntry.php` | Blacklist model |
| `inc/blacklist.php` | WC integration |

### Important Commands

```bash
# Run tests
php artisan test

# Check code standards
php artisan code:analyze

# Deploy
php artisan migrate --force

# Clear caches
php artisan cache:clear
```

---

## License & Copyright

OneShield Platform — All rights reserved

For access, permissions, or questions about documentation usage, contact the OneShield team.

---

**Last Updated:** March 15, 2026
**Maintained By:** OneShield Documentation Team
**Status:** Active & Current
