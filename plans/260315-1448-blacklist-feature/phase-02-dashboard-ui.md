# Phase 2: Dashboard UI — Blacklist Management + Shield Config

## Overview
- Priority: Medium
- Status: complete
- Depends on: Phase 1 complete
- Goal: Admin UI to manage blacklist entries + configure per-shield blacklist behavior

## Related Code Files

**Create:**
- `gateway-panel/resources/js/Pages/Blacklist/Index.vue` — blacklist management page
- `gateway-panel/app/Http/Controllers/Panel/BlacklistController.php` — panel CRUD controller

**Modify:**
- `gateway-panel/resources/js/Pages/Sites/Show.vue` (or Edit) — add blacklist config section per shield
- `gateway-panel/routes/web.php` — add blacklist panel routes
- `gateway-panel/app/Http/Controllers/Panel/ShieldSiteController.php` — handle blacklist_action + trap_shield_id save

## Implementation Steps

### 1. Panel routes (web.php)
```php
Route::prefix('blacklist')->group(function () {
    Route::get('/', [BlacklistController::class, 'index']);
    Route::post('/', [BlacklistController::class, 'store']);       // add custom entry
    Route::delete('/{entry}', [BlacklistController::class, 'destroy']); // delete entry
});
```

### 2. Blacklist Index page (`Pages/Blacklist/Index.vue`)
**Sections:**
- Stats bar: total entries, pgprints count, custom count, last pgprints import date
- Filter tabs: All | Email | Address | pgprints | Custom
- Table: `type` | `value` | `source` | `notes` | `created_at` | delete action
  - pgprints entries: no delete button (read-only, show lock icon)
  - custom entries: delete button
- Add entry form (inline or modal):
  - Type: dropdown (Email / Address)
  - Value: text input
  - Notes: optional text
  - Submit button

### 3. Per-shield blacklist config (Sites/Show or Edit)
Add section "Blacklist Protection" to existing shield edit form:

```
┌─ Blacklist Protection ───────────────────────────────┐
│  Action when buyer is blacklisted:                   │
│  ● Hide payment methods   ○ Route to trap shield     │
│                                                      │
│  [if trap selected]                                  │
│  Trap shield: [dropdown — select shield] ▼           │
└──────────────────────────────────────────────────────┘
```

- Toggle: `hide` | `trap`
- Trap shield dropdown: list of other active shields (exclude self)
- Save via existing shield update form

### 4. Panel BlacklistController
```php
index()   // paginate BlacklistEntry, pass stats
store()   // validate, create with source='custom'
destroy() // only allow deleting source='custom' entries
```

## Todo
- [x] Create `Panel/BlacklistController` (index, store, destroy)
- [x] Create `Pages/Blacklist/Index.vue` with table + add form
- [x] Add nav link to blacklist page in sidebar
- [x] Add "Blacklist Protection" section to shield edit form
- [x] Update `ShieldSiteController::update()` to save `blacklist_action` + `trap_shield_id`
- [x] Validate: `trap_shield_id` required if `action = trap`; cannot self-reference

## Implementation Notes
- Panel/BlacklistController created with index(), store() for bulk-save via textarea, destroy() methods
- Pages/Blacklist/Index.vue with 4 textarea fields + Blacklist Protection UI (hide/trap radio + trap shield dropdown) + 4 system blacklist checkboxes
- Nav link added to AdminLayout
- ShieldSiteController updated (removed blacklist_action/trap_shield_id from shield_sites model)
- SuperAdmin/SystemBlacklistController created + Pages/Admin/SystemBlacklist.vue for system-level management
- System blacklist nav link added to AdminLayout

## Success Criteria
- Admin can view all blacklist entries (filtered by source/type)
- Admin can add custom email/address entries
- Admin cannot delete pgprints entries
- Per-shield blacklist_action saves correctly
- Trap shield dropdown shows valid shields only
