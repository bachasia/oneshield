# OneShield - Payment Gateway Proxy System

> **Mục tiêu:** Build hệ thống OneShield hoàn chỉnh gồm Gateway Panel (SaaS), 2 WooCommerce plugins, và toàn bộ payment flow.

---

## Quyết định kỹ thuật

| Hạng mục | Quyết định |
|---|---|
| Tên dự án | **OneShield** |
| Dev environment | Docker (PHP 8.3 + MySQL 8 + Redis + Nginx) |
| Payment gateways (Giai đoạn 1) | PayPal + Stripe |
| Payment gateways (Giai đoạn 2) | + Airwallex |
| Multi-tenant | Subdomain per tenant (`{tenant}.oneshield.io`) |
| Backend | Laravel 11, PHP 8.3 |
| Frontend | Vue 3 + Inertia.js + Tailwind CSS |
| Queue | Laravel Horizon |
| Cache | Redis |

---

## Kiến trúc tổng thể

```
┌─────────────────────────────────────────────────────────────┐
│                  MONEY SITE (Site Chính)                     │
│   WooCommerce + oneshield-paygates plugin                    │
│   → Kết nối Gateway Panel qua Token Secret                   │
└─────────────────────┬───────────────────────────────────────┘
                      │ API Request (chọn mesh site)
                      ▼
┌─────────────────────────────────────────────────────────────┐
│                  GATEWAY PANEL (SaaS Core)                   │
│   Laravel 11 + Vue 3 + Inertia.js                            │
│   - Quản lý Mesh Sites, Groups, Tokens                       │
│   - Routing logic: chọn site phụ để xử lý payment           │
│   - Dashboard thống kê giao dịch                             │
│   - Multi-tenant: {tenant}.oneshield.io                      │
└─────────────────────┬───────────────────────────────────────┘
                      │ Iframe URL / postMessage
                      ▼
┌─────────────────────────────────────────────────────────────┐
│                MESH SITE (Site Phụ - "Sạch")                 │
│   WooCommerce + oneshield-connect plugin                     │
│   → Lưu PayPal/Stripe API keys (encrypted)                   │
│   → Render iframe thanh toán thực tế                         │
└─────────────────────────────────────────────────────────────┘
```

---

## Repository Structure

```
oneshield/
├── oneshield-plan.md               # File kế hoạch này
├── gateway-panel/                  # Laravel 11 app
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── Auth/
│   │   │   │   ├── Api/
│   │   │   │   │   ├── ConnectController.php
│   │   │   │   │   ├── PaygatesController.php
│   │   │   │   │   └── WebhookController.php
│   │   │   │   └── Panel/
│   │   │   │       ├── DashboardController.php
│   │   │   │       ├── MeshSiteController.php
│   │   │   │       ├── GroupController.php
│   │   │   │       └── TransactionController.php
│   │   │   └── Middleware/
│   │   │       ├── HmacAuthentication.php
│   │   │       └── ResolveTenant.php
│   │   ├── Models/
│   │   │   ├── User.php
│   │   │   ├── MeshSite.php
│   │   │   ├── SiteGroup.php
│   │   │   ├── Transaction.php
│   │   │   └── GatewayToken.php
│   │   └── Services/
│   │       ├── HmacService.php
│   │       ├── SiteRouterService.php
│   │       └── EncryptionService.php
│   ├── database/migrations/
│   ├── resources/
│   │   ├── js/                     # Vue 3 components
│   │   │   ├── Pages/
│   │   │   │   ├── Dashboard.vue
│   │   │   │   ├── Sites/
│   │   │   │   ├── Groups/
│   │   │   │   └── Transactions/
│   │   │   └── Layouts/
│   │   └── views/
│   ├── routes/
│   │   ├── web.php
│   │   └── api.php
│   └── docker-compose.yml
├── plugins/
│   ├── oneshield-connect/          # Plugin site phụ
│   └── oneshield-paygates/         # Plugin site chính
└── docs/
    ├── api-reference.md
    ├── setup-guide.md
    └── deployment.md
```

---

## Database Schema

```sql
-- users: Admin account (1 per tenant)
CREATE TABLE users (
    id BIGINT PRIMARY KEY,
    name VARCHAR(255),
    email VARCHAR(255) UNIQUE,
    password VARCHAR(255),
    tenant_id VARCHAR(100),           -- subdomain identifier
    token_secret VARCHAR(255),        -- HMAC signing key (auto-generated)
    created_at, updated_at
);

-- site_groups: Phân loại mesh sites
CREATE TABLE site_groups (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    name VARCHAR(255),
    description TEXT,
    created_at, updated_at
);

-- mesh_sites: Site phụ đã connect
CREATE TABLE mesh_sites (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    group_id BIGINT NULL,
    name VARCHAR(255),
    url VARCHAR(500),
    -- PayPal credentials (encrypted)
    paypal_client_id TEXT NULL,
    paypal_secret TEXT NULL,
    paypal_mode ENUM('sandbox','live') DEFAULT 'sandbox',
    -- Stripe credentials (encrypted)
    stripe_public_key TEXT NULL,
    stripe_secret_key TEXT NULL,
    stripe_mode ENUM('test','live') DEFAULT 'test',
    -- Airwallex (Phase 2)
    airwallex_client_id TEXT NULL,
    airwallex_api_key TEXT NULL,
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    last_heartbeat_at TIMESTAMP NULL,
    created_at, updated_at
);

-- transactions: Log toàn bộ giao dịch
CREATE TABLE transactions (
    id BIGINT PRIMARY KEY,
    site_id BIGINT,
    order_id VARCHAR(255),
    amount DECIMAL(10,2),
    currency VARCHAR(10) DEFAULT 'USD',
    gateway ENUM('paypal','stripe','airwallex'),
    status ENUM('pending','completed','failed','refunded') DEFAULT 'pending',
    gateway_transaction_id VARCHAR(255) NULL,
    money_site_domain VARCHAR(255),
    raw_response JSON NULL,
    created_at, updated_at
);

-- gateway_tokens: Token secret cho money sites (legacy, dùng user.token_secret)
CREATE TABLE gateway_tokens (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    name VARCHAR(255),               -- label (e.g. "Production Token")
    token VARCHAR(255) UNIQUE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at, updated_at
);
```

---

## API Endpoints

### Authentication: HMAC-SHA256
```
Signature = HMAC-SHA256(JSON(payload) + timestamp, token_secret)
Header: X-Signature: {signature}
Header: X-Timestamp: {unix_timestamp}
// Reject nếu timestamp cũ hơn 5 phút
```

### A. Connect Plugin API (site phụ → gateway)
```
POST /api/connect/register
  Body: { site_url, site_name }
  Response: { site_id, status: "registered" }

POST /api/connect/heartbeat
  Body: { site_id }
  Response: { status: "ok", config: { paypal_mode, stripe_mode } }

GET /api/connect/status/{site_id}
  Response: { site, group, is_active, gateways_configured }
```

### B. Paygates Plugin API (site chính → gateway)
```
POST /api/paygates/get-site
  Body: { gateway, group_id?, order_id, amount, currency }
  Response: { site_id, iframe_url, token }

POST /api/paygates/confirm
  Body: { site_id, order_id, gateway_transaction_id, status }
  Response: { success, transaction_id }

GET /api/paygates/iframe-url
  Params: ?gateway=stripe&group_id=1&order_id=123
  Response: { iframe_url }
```

### C. Webhook/IPN Handlers
```
POST /api/webhook/paypal/{site_id}
POST /api/webhook/stripe/{site_id}
POST /api/webhook/airwallex/{site_id}   (Phase 2)
```

### D. Internal Panel APIs
```
GET  /api/health                         # Health check
GET  /api/plugins/version                # Auto-updater check
```

---

## Payment Flow

```
1. Khách checkout tại money site
   → WooCommerce gọi process_payment()
   
2. oneshield-paygates plugin gọi:
   POST {gateway_url}/api/paygates/get-site
   { gateway: "stripe", group_id: 1, order_id: "123", amount: 99.00 }
   
3. Gateway Panel:
   → Chọn mesh site active trong group (random load balancing)
   → Tạo iframe_url: https://{mesh_site}/checkout?token=xxx&order=123
   → Trả về iframe_url
   
4. Plugin render <iframe src="{iframe_url}"> trong checkout page

5. Khách nhập thông tin thanh toán trong iframe
   → Stripe/PayPal xử lý payment

6. Mesh site gửi postMessage:
   { status: "success", transaction_id: "ch_xxx", order_id: "123" }

7. oneshield-paygates nhận postMessage
   → Gọi POST /api/paygates/confirm
   
8. Gateway Panel:
   → Cập nhật transaction log
   → Gửi notification nếu cần
   
9. Plugin update WC order status → redirect thank-you page
```

---

## Giai đoạn triển khai

### [x] Giai đoạn 0: Kế hoạch (Done)
- [x] Viết file kế hoạch oneshield-plan.md

### [x] Giai đoạn 1: Gateway Panel Backend (Tuần 1-2)

#### 1.1 Setup Project
- [x] Khởi tạo Laravel 11 trong `gateway-panel/`
- [x] Setup `docker-compose.yml`: PHP 8.3, MySQL 8, Redis, Nginx
- [x] Cài packages: inertiajs/inertia-laravel, tightenco/ziggy, laravel/sanctum
- [x] Setup Vite + Vue 3 + Tailwind CSS
- [x] Cấu hình `.env` (MySQL + Redis)

#### 1.2 Multi-tenant Setup
- [x] Middleware `HandleInertiaRequests` với shared data
- [ ] Middleware `ResolveTenant` → resolve từ subdomain (TODO: implement nếu cần multi-tenant thực sự)

#### 1.3 Database Migrations
- [x] Migration: `users` (thêm tenant_id, token_secret)
- [x] Migration: `site_groups`
- [x] Migration: `mesh_sites` (credentials encrypted)
- [x] Migration: `transactions`
- [x] Migration: `gateway_tokens`

#### 1.4 Authentication
- [x] Route `GET /account/admin` → form tạo admin lần đầu
- [x] Route `POST /account/admin` → tạo admin + generate token_secret
- [x] Login/Logout controllers
- [x] Token Secret hiển thị ở sidebar + Settings page

#### 1.5 Models & Services
- [x] Model `MeshSite` với encrypted casting (AES-256 via Laravel Crypt)
- [x] Model `Transaction`, `SiteGroup`, `GatewayToken`
- [x] Service `HmacService` (sign + verify + generate token)
- [x] Service `SiteRouterService` (chọn mesh site + circuit breaker)
- [x] Middleware `HmacAuthentication`

#### 1.6 API - Connect Plugin
- [x] `POST /api/connect/register`
- [x] `POST /api/connect/heartbeat`
- [x] `GET /api/connect/status/{site_id}`

#### 1.7 API - Paygates Plugin
- [x] `POST /api/paygates/get-site`
- [x] `POST /api/paygates/confirm`
- [x] `GET /api/paygates/iframe-url`

#### 1.8 API - Webhooks
- [x] `POST /api/webhook/paypal/{site_id}`
- [x] `POST /api/webhook/stripe/{site_id}`
- [x] HMAC middleware cho Connect + Paygates APIs
- [x] Rate limiting (200 req/min cho webhooks)

---

### [x] Giai đoạn 2: Gateway Panel Frontend (Tuần 3)

#### 2.1 Layout
- [x] `AppLayout.vue` - sidebar + header
- [x] Sidebar: Dashboard, Sites, Groups, Transactions, Settings
- [x] Header: dark mode toggle, user info, logout
- [x] Token Secret display + copy button ở sidebar footer
- [x] Flash message display

#### 2.2 Trang Dashboard
- [x] Stats cards: total sites, active sites, transactions today, total revenue
- [x] Recent transactions table

#### 2.3 Trang Sites (Payment Sites)
- [x] `Sites/Index.vue` - danh sách mesh sites với filter
- [x] Columns: Name+URL, Group, Gateways, Status toggle, Last Active, Actions
- [x] Filter: by group, by status
- [x] Toggle Enable/Disable site
- [x] Add Site modal (name, url, group, PayPal keys, Stripe keys, mode)
- [x] Site Settings modal (update keys + group)
- [x] Delete site

#### 2.4 Trang Groups
- [x] `Groups/Index.vue` - danh sách groups dạng cards
- [x] Create / Edit / Delete group modal
- [x] Hiển thị số sites trong group + Group ID

#### 2.5 Trang Transactions
- [x] `Transactions/Index.vue` - bảng log với filter
- [x] Filter: gateway, status, date_from, date_to
- [x] Export CSV link
- [x] `Transactions/Show.vue` - chi tiết transaction + raw response

#### 2.6 Trang Settings
- [x] Token Secret display (masked/show toggle) + copy
- [x] Regenerate Token button
- [x] Webhook URLs display + copy

---

### [x] Giai đoạn 3: Plugin oneshield-connect (Tuần 4)

- [x] `oneshield-connect.php` main plugin file với hooks + activation/deactivation
- [x] `inc/base.php` - core helpers (get/update option, sign_request, build_headers)
- [x] `inc/settings.php` - WP Admin settings page (Connect Now, Disconnect, status)
- [x] `inc/remote.php` - API calls (register_site, run_heartbeat)
- [x] `inc/heartbeat.php` - heartbeat status helper
- [x] `inc/order.php` - WC order helpers
- [x] `checkout/stripe.php` - Stripe Elements iframe page + AJAX endpoints
- [x] `checkout/paypal.php` - PayPal Buttons SDK iframe page + AJAX endpoints
- [x] WP Cron heartbeat mỗi 5 phút
- [x] `?fe-checkout` param handler (router sang stripe/paypal)

---

### [x] Giai đoạn 4: Plugin oneshield-paygates (Tuần 5)

- [x] `oneshield-paygates.php` main plugin file
- [x] `includes/class-os-payment-base.php` - Base WC Payment Gateway class
  - [x] `sign_request()` HMAC signing
  - [x] `get_iframe_url()` → GET /api/paygates/get-site
  - [x] `confirm_with_panel()` → POST /api/paygates/confirm
- [x] `includes/class-os-stripe.php` - Stripe gateway
- [x] `includes/class-os-paypal.php` - PayPal gateway
- [x] `includes/class-os-ipn-handler.php` - Webhook/IPN handler
- [x] `includes/functions.php` - Script enqueue
- [x] `assets/js/checkout.js` - iframe modal + postMessage listener + AJAX confirm

---

### [ ] Giai đoạn 5: Security & Polish (Tuần 6)

- [ ] HMAC-SHA256 middleware hoàn chỉnh
- [ ] Timestamp validation (reject > 5 phút)
- [ ] AES-256 encrypt/decrypt cho API keys trong DB
- [ ] Rate limiting per token (100 req/min)
- [ ] CORS whitelist cho money site domains
- [ ] CSP headers cho iframe
- [ ] Laravel Horizon setup cho webhook queue
- [ ] Circuit breaker: disable site sau N lỗi liên tiếp
- [ ] Auto-updater: `GET /api/plugins/version`
- [ ] Plugin download endpoint

---

### [ ] Giai đoạn 6: Testing (Tuần 7)

- [ ] PayPal Sandbox end-to-end test
- [ ] Stripe Test Mode end-to-end test
- [ ] Test group routing (nhiều mesh sites)
- [ ] Test failover khi mesh site down
- [ ] Test duplicate IPN/webhook handling
- [ ] Test postMessage cross-origin
- [ ] Test HMAC replay attack prevention

---

### [ ] Giai đoạn 7: Performance & Deploy (Tuần 8)

- [ ] Redis cache cho danh sách active mesh sites
- [ ] Database indexing (transactions.site_id, transactions.created_at)
- [ ] CDN cho static assets plugins
- [ ] Production Docker config (Nginx + PHP-FPM optimized)
- [ ] GitHub Actions CI/CD deploy lên VPS
- [ ] SSL/HTTPS configuration
- [ ] Monitoring: health check endpoint + alerting

---

## Security Design

```
Request signing (Connect + Paygates APIs):
  payload = JSON.stringify(request_body)
  signature = HMAC-SHA256(payload + timestamp, token_secret)
  
Headers gửi kèm mỗi request:
  X-OneShield-Signature: {signature}
  X-OneShield-Timestamp: {unix_timestamp}
  
Gateway Panel verify:
  1. Check |now - timestamp| < 300 (5 phút)
  2. Recompute signature, so sánh với header
  3. Nếu không khớp → 401 Unauthorized
```

---

## Pricing Model (tham khảo)

- **Free Trial:** 100 transactions/tháng, 2 mesh sites
- **Basic:** 1,000 transactions/tháng, 10 mesh sites
- **Pro:** Unlimited, priority support, Airwallex
- **Billing:** Stripe Subscription tích hợp vào Gateway Panel

---

*Cập nhật: 2026-03-02 | Dựa trên kế hoạch MeshCheckout gốc*
