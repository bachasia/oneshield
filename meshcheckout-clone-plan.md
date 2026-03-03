# 🚀 Kế hoạch Clone MeshCheckout - Hệ thống Payment Gateway Proxy

> **Mục tiêu:** Build lại hệ thống MeshCheckout hoàn chỉnh gồm Gateway Panel (SaaS), 2 WooCommerce plugins, và toàn bộ payment flow.

---

## 📐 Kiến trúc tổng thể

```
┌─────────────────────────────────────────────────────────────┐
│                    MONEY SITE (Site Chính)                   │
│   WooCommerce + meshcheckout-paygates plugin                 │
│   → Kết nối Gateway Panel qua Token Secret                  │
└─────────────────────┬───────────────────────────────────────┘
                      │ API Request (chọn mesh site)
                      ▼
┌─────────────────────────────────────────────────────────────┐
│                   GATEWAY PANEL (SaaS Core)                  │
│   Laravel 11 + Vue 3 + Inertia.js                            │
│   - Quản lý Mesh Sites, Groups, Tokens                       │
│   - Routing logic: chọn site phụ để xử lý payment           │
│   - Dashboard thống kê giao dịch                             │
└─────────────────────┬───────────────────────────────────────┘
                      │ Iframe URL / postMessage
                      ▼
┌─────────────────────────────────────────────────────────────┐
│                  MESH SITE (Site Phụ - "Sạch")              │
│   WooCommerce + meshcheckout-connect plugin                  │
│   → Lưu PayPal/Stripe API keys                               │
│   → Render iframe thanh toán thực tế                         │
└─────────────────────────────────────────────────────────────┘
```

---

## 📦 Giai đoạn 1: Gateway Panel Backend (Tuần 1–3)

### 1.1 Setup Project (2–3 ngày)

- [ ] Khởi tạo Laravel 11 project
- [ ] Cài đặt packages: Inertia.js, Vue 3, Tailwind CSS, Laravel Sanctum
- [ ] Setup Docker (PHP 8.3 + MySQL 8 + Redis + Nginx)
- [ ] Cấu hình `.env` multi-tenant (mỗi khách hàng có subdomain riêng)
- [ ] Setup CI/CD cơ bản (GitHub Actions deploy lên VPS)

**Stack:**
```
Backend:  Laravel 11, PHP 8.3
Frontend: Vue 3 + Inertia.js + Tailwind CSS
Database: MySQL 8
Cache:    Redis
Queue:    Laravel Horizon (xử lý webhook async)
Server:   Nginx + OpenLiteSpeed
```

---

### 1.2 Database Schema (2 ngày)

```sql
-- Bảng chính
users                    -- Admin account (1 per gateway instance)
mesh_sites               -- Site phụ đã connect
site_groups              -- Group phân loại site phụ
transactions             -- Log toàn bộ giao dịch
payment_accounts         -- API keys PayPal/Stripe/Airwallex (encrypted)
gateway_tokens           -- Token secret cho money sites

-- Chi tiết bảng mesh_sites
id, name, url, group_id, 
paypal_client_id, paypal_secret (encrypted),
stripe_public_key, stripe_secret_key (encrypted),
airwallex_client_id, airwallex_api_key (encrypted),
paypal_mode (sandbox/live),
stripe_mode (test/live),
is_active, created_at, updated_at

-- Chi tiết bảng transactions
id, site_id, order_id, amount, currency,
gateway (paypal/stripe/airwallex),
status (pending/completed/failed/refunded),
gateway_transaction_id, raw_response,
money_site_domain, created_at
```

---

### 1.3 Authentication & Admin Setup (2 ngày)

- [ ] Route `/account/admin` → Tạo admin lần đầu (chỉ được tạo 1 lần)
- [ ] Login page tại `/` (gateway URL chính)
- [ ] Middleware kiểm tra đã có admin chưa
- [ ] Generate Token Secret tự động khi tạo admin
- [ ] Hiển thị Token Secret ở footer dashboard + icon copy

---

### 1.4 API Endpoints (4 ngày)

**A. Connect Plugin API** (site phụ → gateway):
```
POST /api/connect/register      # Đăng ký mesh site mới
POST /api/connect/heartbeat     # Kiểm tra site còn active
GET  /api/connect/status        # Lấy thông tin config của site
```

**B. Paygates Plugin API** (site chính → gateway):
```
POST /api/paygates/get-site     # Lấy mesh site phù hợp (random/group)
POST /api/paygates/confirm      # Xác nhận order sau payment
GET  /api/paygates/iframe-url   # Lấy URL iframe để nhúng
```

**C. Webhook/IPN Handler:**
```
POST /api/webhook/paypal/{site_id}     # Nhận IPN từ PayPal
POST /api/webhook/stripe/{site_id}     # Nhận webhook từ Stripe
POST /api/webhook/airwallex/{site_id}  # Nhận webhook từ Airwallex
```

**Authentication:** HMAC-SHA256 với Token Secret, timestamp để chống replay attack.

---

## 🖥️ Giai đoạn 2: Gateway Panel Frontend (Tuần 3–4)

### 2.1 Dashboard Layout (2 ngày)

- [ ] Sidebar navigation
- [ ] Header với Token Secret display + copy button
- [ ] Responsive design (Tailwind CSS)
- [ ] Dark/Light mode

### 2.2 Các trang chính (4 ngày)

**Payment Sites > All Sites:**
- [ ] Danh sách tất cả mesh sites (tên, URL, group, status, last active)
- [ ] Nút Settings cho từng site
- [ ] Toggle Enable/Disable từng site
- [ ] Filter theo group, gateway, status

**Site Settings Modal/Page:**
- [ ] PayPal Client ID + Secret (masked)
- [ ] Stripe Public Key + Secret (masked)  
- [ ] Airwallex Client ID + API Key (masked)
- [ ] Mode selector (Sandbox/Live cho từng gateway)
- [ ] Group assignment
- [ ] Is Active toggle

**Groups Management:**
- [ ] Tạo/sửa/xóa groups
- [ ] Gán sites vào group
- [ ] Xem sites trong group

**Transactions Log:**
- [ ] Bảng log giao dịch real-time
- [ ] Filter: theo site, gateway, status, ngày
- [ ] Export CSV
- [ ] Chi tiết từng transaction

**Settings:**
- [ ] Token Secret regenerate
- [ ] Webhook URLs display
- [ ] Notification settings (Telegram/Email khi có lỗi)

---

## 🔌 Giai đoạn 3: Plugin meshcheckout-connect (Tuần 4–5)

> Plugin cài lên **Site Phụ** (mesh site). Dựa trên source code gốc đã có.

### 3.1 Cấu trúc plugin

```
meshcheckout-connect/
├── meshcheckout-connect.php     # Main plugin file
├── checkout/
│   ├── stripe.php               # Render iframe Stripe
│   ├── paypal.php               # Render iframe PayPal  
│   └── airwallex.php            # Render iframe Airwallex
├── inc/
│   ├── base.php                 # Core functions
│   ├── form.php                 # Form rendering
│   ├── ipn.php                  # IPN handler
│   ├── order.php                # Order management
│   ├── remote.php               # Gọi API về Gateway Panel
│   ├── stripe.php               # Stripe integration
│   ├── paypal.php               # PayPal integration
│   └── airwallex.php            # Airwallex integration
├── assets/
│   ├── stripe-connect.js
│   ├── paypal-connect.js
│   └── airwallex-connect.js
└── inc/stripe/                  # Stripe PHP SDK
└── inc/ppsdkv2/                 # PayPal Server SDK v2
└── inc/airwallex/               # Airwallex PHP API
```

### 3.2 Tính năng cần build

- [ ] **Settings page** (WP Admin > Settings > MeshCheckout Connect hoặc menu MC Connect)
  - [ ] Nhập Gateway URL
  - [ ] Nút "Connect Now" → gọi API đăng ký site
  - [ ] Hiển thị trạng thái connected/disconnected

- [ ] **Xử lý checkout** (detect `?fe-checkout` param):
  - [ ] Load Stripe Elements / PayPal SDK / Airwallex form
  - [ ] Xử lý payment
  - [ ] postMessage kết quả về site chính

- [ ] **API Keys management:**
  - [ ] Plugin nhận API keys từ Gateway Panel (không nhập trực tiếp tại site phụ)
  - [ ] Lưu encrypted trong WP options

- [ ] **Heartbeat:** Định kỳ ping về Gateway Panel để báo site còn hoạt động

---

## 🛒 Giai đoạn 4: Plugin meshcheckout-paygates (Tuần 5–6)

> Plugin cài lên **Site Chính** (money site). Dựa trên source code gốc đã có.

### 4.1 Cấu trúc plugin

```
meshcheckout-paygates/
├── meshcheckout-paygates.php         # Main plugin file
├── stripe_payment.php                # WC Payment Gateway - Stripe
├── stripecheckout_payment.php        # WC Payment Gateway - Stripe Checkout
├── paypal_payment.php                # WC Payment Gateway - PayPal
├── airwallex_payment.php             # WC Payment Gateway - Airwallex
├── includes/
│   ├── class-meshcheckout-payment.php    # Base payment class
│   ├── class-meshcheckout-request.php    # Request handling
│   ├── class-meshcheckout-response.php   # Response handling
│   ├── class-meshcheckout-ipn-handler.php # Webhook processing
│   ├── settings-stripe.php
│   ├── settings-paypal.php
│   ├── settings-airwallex.php
│   ├── functions.php
│   ├── tracking-table-list.php           # Transaction tracking UI
│   ├── updater.php                       # Auto-updater
│   └── blocks/                           # WooCommerce Blocks support
│       ├── class-wc-meshcheckout-stripe-blocks.php
│       └── class-wc-meshcheckout-paypal-blocks.php
└── assets/js/
    ├── checkout-stripe.js
    ├── checkout-paypal.js
    └── checkout-airwallex.js
```

### 4.2 Settings cần build cho từng gateway

**PayPal Settings (WP Admin > MeshCheckout > PayPal Settings):**
- [ ] Enable/Disable
- [ ] Gateway URL
- [ ] Token Secret
- [ ] Group ID (để route đến group sites cụ thể)
- [ ] PayPal Sandbox mode toggle
- [ ] Disable Shipping Address option
- [ ] Debug log toggle

**Stripe Settings:**
- [ ] Enable/Disable
- [ ] Gateway URL
- [ ] Token Secret
- [ ] Group ID
- [ ] Test/Live mode toggle
- [ ] Debug log toggle

**StripeCheckout Settings:** (tương tự Stripe)

**Airwallex Settings:** (tương tự)

### 4.3 Payment Flow Implementation

```
1. Khách checkout → WC gọi payment gateway process_payment()
2. Plugin gọi Gateway Panel API: POST /api/paygates/get-site
   - Params: gateway, group_id, order_id, amount, currency, token
3. Gateway Panel trả về: iframe_url của mesh site được chọn
4. Plugin render iframe trong checkout page
5. Khách thanh toán trong iframe (Stripe/PayPal form)
6. Mesh site gửi postMessage: {status, transaction_id, order_id}
7. Plugin nhận postMessage → gọi Gateway Panel confirm
8. Gateway Panel cập nhật transaction log
9. Plugin update WC order → redirect thank you page
```

### 4.4 WooCommerce Blocks Support

- [ ] Register payment method cho Gutenberg Blocks checkout
- [ ] JavaScript integration với `@woocommerce/blocks-registry`

---

## 🔐 Giai đoạn 5: Security & Production (Tuần 6–7)

### 5.1 Security

- [ ] **HMAC Authentication:** Mọi API call ký bằng `HMAC-SHA256(payload + timestamp, token_secret)`
- [ ] **Timestamp validation:** Từ chối request cũ hơn 5 phút (chống replay)
- [ ] **Encrypt sensitive data:** API keys trong database dùng AES-256 (Laravel encrypt)
- [ ] **Rate limiting:** API endpoints (100 req/min per token)
- [ ] **CORS:** Whitelist domain của money sites
- [ ] **iframe CSP:** Content Security Policy headers
- [ ] **HTTPS only:** Force HTTPS trên mọi component
- [ ] **SQL injection prevention:** Eloquent ORM, prepared statements

### 5.2 Reliability

- [ ] **Queue:** Webhook processing chạy async qua Laravel Horizon
- [ ] **Retry logic:** Auto retry khi payment confirmation fail
- [ ] **Circuit breaker:** Tạm disable mesh site khi liên tục lỗi
- [ ] **Health check:** `/api/health` endpoint cho monitoring
- [ ] **Logging:** Structured logs với Monolog → ELK hoặc simple file

### 5.3 Auto-updater

- [ ] Plugin version check API: `GET /api/plugins/version`
- [ ] Download zip mới khi có update
- [ ] Tích hợp với WordPress plugin update mechanism

---

## 📊 Giai đoạn 6: Testing & Polish (Tuần 7–8)

### 6.1 Test Payment Flow

- [ ] Test PayPal Sandbox end-to-end
- [ ] Test Stripe Test Mode end-to-end
- [ ] Test Airwallex sandbox
- [ ] Test với nhiều mesh sites cùng lúc
- [ ] Test Group routing
- [ ] Test IPN/Webhook handling
- [ ] Test postMessage cross-origin

### 6.2 Edge Cases

- [ ] Xử lý khi mesh site down → failover sang site khác
- [ ] Xử lý duplicate IPN
- [ ] Xử lý payment timeout
- [ ] Xử lý currency conversion

### 6.3 Performance

- [ ] Redis cache cho danh sách active mesh sites
- [ ] Database indexing cho transactions table
- [ ] CDN cho static assets plugins

---

## 🗓️ Timeline Tổng Quan

| Tuần | Công việc |
|------|-----------|
| 1 | Setup project, DB schema, Auth |
| 2 | Gateway Panel API endpoints |
| 3 | Gateway Panel Frontend (Dashboard, Sites, Groups) |
| 4 | Plugin meshcheckout-connect |
| 5 | Plugin meshcheckout-paygates |
| 6 | Security, Auto-updater, Webhook handling |
| 7 | Testing end-to-end, Bug fixes |
| 8 | Performance, Documentation, Deploy production |

---

## 💰 Pricing Model (để tham khảo khi build billing)

Dựa trên trang MeshCheckout gốc, có thể build:
- **Free Trial:** Giới hạn transaction/tháng
- **Basic:** X sites phụ, Y money sites
- **Pro:** Unlimited sites, priority support
- **Billing:** Stripe Subscription tích hợp vào Gateway Panel

---

## 📁 Repository Structure Đề Xuất

```
meshcheckout-clone/
├── gateway-panel/               # Laravel 11 app
│   ├── app/
│   ├── resources/js/            # Vue 3 components
│   └── docker-compose.yml
├── plugins/
│   ├── meshcheckout-connect/    # Plugin site phụ
│   └── meshcheckout-paygates/  # Plugin site chính
└── docs/
    ├── api-reference.md
    ├── setup-guide.md
    └── deployment.md
```

---

## 🚦 Bước Tiếp Theo Ngay

1. **Ưu tiên 1:** Build Gateway Panel backend trước (API endpoints)
2. **Ưu tiên 2:** Build frontend Dashboard (cần để test API)
3. **Ưu tiên 3:** Adapt plugin source code đã có để connect vào Gateway mới
4. **Bắt đầu từ đâu?** → Setup Laravel project + Database schema + Auth endpoint

---

*Tài liệu này dựa trên reverse engineering plugin source code và hướng dẫn chính thức tại meshcheckout.com/huong-dan-meshcheckout/*
