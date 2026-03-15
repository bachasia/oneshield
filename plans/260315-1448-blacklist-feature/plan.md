---
title: Blacklist Feature Implementation
status: complete
created: 2026-03-15
completed: 2026-03-15
---

# Blacklist Feature

Prevent lawyer test-purchases by blacklisting emails/addresses. Per-shield config: hide payment OR route to trap shield.

## Phases

| Phase | Description | Status |
|-------|-------------|--------|
| [Phase 1](phase-01-backend-model-and-api.md) | Backend: Model, migration, seeder (one-time pgprints import), API endpoint | complete |
| [Phase 2](phase-02-dashboard-ui.md) | Dashboard: Blacklist management UI + shield config (hide/trap) | complete |
| [Phase 3](phase-03-wc-plugin.md) | WooCommerce plugin: Checkout hook, blacklist check, hide/trap logic | complete |

## Key Decisions
- pgprints.io list imported **once** via seeder/artisan command — no periodic sync
- Admin can add/delete custom entries after import
- Check layer: **WooCommerce plugin server-side** (hooks into WC checkout)
- Match: email exact + address normalized exact (fuzzy deferred to later)
- Config per shield: `blacklist_action = hide | trap`, `trap_shield_id`

## Data Flow
```
pgprints.io (one-time import via artisan command)
    ↓
blacklist_entries table (email[], address[])
    ↓ (GET /api/blacklist — HMAC auth, cached 1h via WP transient)
WooCommerce Plugin
    ↓ (woocommerce_available_payment_gateways hook)
    ├── action=hide  → return [] (no gateways shown)
    └── action=trap  → tag order, send to OneShieldX with trap_shield_id
```
