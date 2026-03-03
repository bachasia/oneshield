# OneShield — Multi-tenant SaaS Plan

Generated: 2026-03-03  
Model: Subdomain-per-tenant + Super Admin panel + Manual subscription billing

---

## Kiến trúc tổng quan

```
https://admin.oneshieldx.com         ← Super Admin (bạn)
https://zidoecom.oneshieldx.com      ← Tenant A (khách hàng)
https://anothershop.oneshieldx.com   ← Tenant B (khách hàng)
```

- **1 Laravel app duy nhất** chạy tất cả subdomains
- Middleware `ResolveTenant` extract subdomain từ HTTP Host → load đúng User/Tenant
- `admin` subdomain bypass tenant resolution → vào Super Admin routes
- Mỗi tenant hoàn toàn isolated: shield sites, transactions, tokens đều scope theo `user_id`

---

## Plans

| Plan | Giá | Max Shield Sites | Notes |
|------|-----|------------------|-------|
| `start` | $29/tháng | 1 | |
| `pro` | $99/tháng | 5 | |
| `enterprise` | Contact | 999 | Unlimited effective |
| `trial` | Free | 1 | 14 ngày trial |

---

## Checklist triển khai

### PHASE 1 — Database & Models

- [x] **DB-01** Migration: tạo bảng `plans`
- [x] **DB-02** Migration: tạo bảng `subscriptions`
- [x] **DB-03** Migration: thêm `is_super_admin` (boolean) vào bảng `users`
- [x] **DB-04** Seeder: seed 4 plans mặc định (trial, start, pro, enterprise)
- [x] **DB-05** Model `Plan`
- [x] **DB-06** Model `Subscription` + relationship với `User` và `Plan`
- [x] **DB-07** Update model `User`: thêm relationships + helper methods

### PHASE 2 — Tenant Middleware & Routing

- [x] **MW-01** Middleware `ResolveTenant`: extract subdomain → load tenant User → bind vào request
- [x] **MW-02** Middleware `SuperAdmin`: kiểm tra `is_super_admin = true`
- [x] **MW-03** Middleware `TenantSubscriptionActive`: kiểm tra subscription chưa expired + chưa suspended
- [x] **MW-04** Cập nhật `routes/web.php`: tách route group theo subdomain context
- [x] **MW-05** Cập nhật `bootstrap/app.php`: đăng ký middleware aliases

### PHASE 3 — Shield Site Limit Enforcement

- [x] **SL-01** Thêm method `canCreateShieldSite()` vào `User` model
- [x] **SL-02** Cập nhật `ShieldSiteController::store()`: check limit trước khi tạo, trả về lỗi với upgrade message
- [x] **SL-03** Truyền `subscription` + `plan` prop xuống Inertia (qua `HandleInertiaRequests`)
- [x] **SL-04** Hiển thị plan badge + usage bar trong sidebar `AppLayout.vue`
- [x] **SL-05** Hiển thị upgrade modal khi hit limit trong `Sites/Index.vue`

### PHASE 4 — Super Admin Backend

- [x] **SA-01** `SuperAdminController` với các actions:
  - `dashboard()` — stats tổng: tổng tenants, MRR, active subscriptions
  - `tenants()` — danh sách tất cả tenants + plan + trạng thái
  - `createTenant()` — tạo tenant mới (name, email, password, tenant_id, plan)
  - `showTenant()` — chi tiết 1 tenant
  - `updateSubscription()` — đổi plan, set expiry, ghi notes
  - `suspendTenant()` / `unsuspendTenant()`
  - `loginAsTenant()` — impersonate (xem panel của khách từ góc nhìn của họ)
- [x] **SA-02** Routes group `admin.*` với middleware `SuperAdmin`

### PHASE 5 — Super Admin UI (Vue/Inertia)

- [x] **UI-01** Layout `AdminLayout.vue` — sidebar riêng với màu khác (dark/slate)
- [x] **UI-02** `Admin/Dashboard.vue` — stats cards: Total Tenants, Active, MRR, Trial
- [x] **UI-03** `Admin/Tenants/Index.vue` — bảng tenants, filter by plan/status, search
- [x] **UI-04** `Admin/Tenants/Create.vue` — form tạo tenant mới
- [x] **UI-05** `Admin/Tenants/Show.vue` — chi tiết tenant:
  - Thông tin cơ bản (name, email, subdomain)
  - Subscription card: plan hiện tại, expiry, trạng thái
  - Actions: Change Plan, Set Expiry, Add Notes, Suspend, Login As
  - Shield Sites count vs limit
  - Transaction volume (tổng 30 ngày)

### PHASE 6 — Nginx + DNS (hướng dẫn, không code)

- [ ] **INFRA-01** Wildcard DNS: `*.oneshieldx.com → VPS IP`
- [ ] **INFRA-02** Nginx: wildcard SSL cert + routing tất cả subdomains vào cùng 1 app
- [ ] **INFRA-03** Cập nhật `.env`: `APP_URL=https://admin.oneshieldx.com`, `SESSION_DOMAIN=.oneshieldx.com`

### PHASE 7 — Tenant Onboarding Flow

- [x] **ON-01** Cập nhật `AdminSetupController`: chỉ cho phép tạo Super Admin (is_super_admin = true) lần đầu
- [ ] **ON-02** Email thông báo cho tenant khi account được tạo (optional, có thể dùng log driver)

---

## Chi tiết kỹ thuật

### Bảng `plans`

```sql
id, name (start/pro/enterprise/trial), label (Start/Pro/Enterprise/Trial),
price_usd (29/99/0/0), max_shield_sites (1/5/999/1),
is_active (boolean), created_at, updated_at
```

### Bảng `subscriptions`

```sql
id, user_id (FK), plan_id (FK),
status (active/suspended/expired/trial),
expires_at (nullable — null = không hết hạn / enterprise),
notes (text, nullable — admin ghi chú lý do đổi plan, thanh toán...),
created_by_admin_id (nullable FK → users.id),
created_at, updated_at
```

### Middleware `ResolveTenant` — logic

```
1. Lấy HTTP Host header → extract subdomain
   host = "zidoecom.oneshieldx.com"
   subdomain = "zidoecom"

2. Nếu subdomain = "admin" → skip (SuperAdmin middleware xử lý)

3. Tìm User WHERE tenant_id = subdomain
   → Không tìm thấy → abort(404)
   → Tìm thấy → bind vào request như "tenant"

4. Các panel routes dùng $request->tenant() thay vì $request->user()
   (user() vẫn là người đang đăng nhập session)
```

### Shield site limit check

```php
// User model
public function canCreateShieldSite(): bool
{
    $max = $this->activeSubscription?->plan?->max_shield_sites ?? 0;
    $current = $this->shieldSites()->count();
    return $current < $max;
}

public function shieldSiteLimitMessage(): string
{
    $plan = $this->activeSubscription?->plan?->label ?? 'Free';
    $max  = $this->activeSubscription?->plan?->max_shield_sites ?? 0;
    return "Your {$plan} plan allows {$max} shield site(s). Upgrade to add more.";
}
```

### Inertia shared props (update `HandleInertiaRequests`)

Thêm vào `share()`:
```php
'subscription' => fn() => $request->user() ? [
    'plan'        => $request->user()->activeSubscription?->plan?->only(['name','label','max_shield_sites']),
    'status'      => $request->user()->activeSubscription?->status,
    'expires_at'  => $request->user()->activeSubscription?->expires_at,
    'sites_used'  => $request->user()->shieldSites()->count(),
    'sites_limit' => $request->user()->activeSubscription?->plan?->max_shield_sites ?? 0,
] : null,
```

---

## Thứ tự implement

```
DB-01 → DB-02 → DB-03 → DB-04 → DB-05 → DB-06 → DB-07
    ↓
MW-01 → MW-02 → MW-03 → MW-04 → MW-05
    ↓
SL-01 → SL-02 → SL-03 → SL-04 → SL-05
    ↓
SA-01 → SA-02
    ↓
UI-01 → UI-02 → UI-03 → UI-04 → UI-05
    ↓
ON-01
    ↓
INFRA-01 → INFRA-02 → INFRA-03 (deploy)
```

---

## Fix Log

| Task | Status | Notes |
|------|--------|-------|
| DB-01 | ✅ | Migration `plans` created |
| DB-02 | ✅ | Migration `subscriptions` created |
| DB-03 | ✅ | `is_super_admin` added to `users` |
| DB-04 | ✅ | `PlanSeeder` seeds trial/start/pro/enterprise |
| DB-05 | ✅ | `Plan` model + helpers |
| DB-06 | ✅ | `Subscription` model + relationships |
| DB-07 | ✅ | `User` subscription relationships + site limit helpers |
| MW-01 | ✅ | `ResolveTenant` loads tenant from subdomain |
| MW-02 | ✅ | `SuperAdmin` middleware restricts admin panel |
| MW-03 | ✅ | `TenantSubscriptionActive` blocks expired/suspended tenants |
| MW-04 | ✅ | `admin.*` and tenant route groups separated |
| MW-05 | ✅ | Middleware aliases + app host config/shared props |
| SL-01 | ✅ | `canCreateShieldSite()` added |
| SL-02 | ✅ | Shield site creation blocked at plan limit |
| SL-03 | ✅ | Inertia shares subscription/usage props |
| SL-04 | ✅ | Plan badge + usage bar in `AppLayout.vue` |
| SL-05 | ✅ | Upgrade modal shown in `Sites/Index.vue` |
| SA-01 | ✅ | `SuperAdminController` implemented |
| SA-02 | ✅ | Admin route group protected by middleware |
| UI-01 | ✅ | `AdminLayout.vue` created |
| UI-02 | ✅ | `Admin/Dashboard.vue` created |
| UI-03 | ✅ | `Admin/Tenants/Index.vue` created |
| UI-04 | ✅ | `Admin/Tenants/Create.vue` created |
| UI-05 | ✅ | `Admin/Tenants/Show.vue` created |
| ON-01 | ✅ | Initial setup now creates super admin |
| INFRA-01 | ⬜ | Manual — DNS config |
| INFRA-02 | ⬜ | Manual — Nginx config |
| INFRA-03 | ⬜ | Manual — .env update |
