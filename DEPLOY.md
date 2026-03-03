# OneShield — Hướng dẫn Deploy VPS & Setup Shield Site

> Stack: Laravel 12 + Inertia/Vue 3 · MySQL 8 · Redis 7 · Nginx · Docker Compose  
> Yêu cầu VPS: Ubuntu 22.04+, 2 vCPU / 2 GB RAM tối thiểu, domain đã trỏ A-record

---

## Phần 1 — Deploy Gateway Panel lên VPS

### 1.1 Chuẩn bị VPS

```bash
# Đăng nhập VPS
ssh root@YOUR_VPS_IP

# Cập nhật hệ thống
apt update && apt upgrade -y

# Cài Docker + Docker Compose
curl -fsSL https://get.docker.com | sh
apt install -y docker-compose-plugin git

# Kiểm tra
docker --version
docker compose version
```

### 1.2 Upload code lên VPS

**Cách A — Git clone (khuyến nghị):**
```bash
cd /var/www
git clone https://github.com/YOUR_ORG/oneshield.git
cd oneshield/gateway-panel
```

**Cách B — Upload trực tiếp:**
```bash
# Từ máy local
scp -r ./gateway-panel root@YOUR_VPS_IP:/var/www/oneshield/gateway-panel
```

### 1.3 Tạo file `.env`

```bash
cd /var/www/oneshield/gateway-panel
cp .env.example .env
nano .env
```

Chỉnh sửa các giá trị sau (bắt buộc):

```dotenv
APP_NAME=OneShield
APP_ENV=production
APP_KEY=                          # sẽ generate ở bước sau
APP_DEBUG=false
APP_URL=https://admin.oneshieldx.com
APP_HOST=oneshieldx.com

LOG_CHANNEL=stack
LOG_LEVEL=error

# Database — dùng MySQL trong Docker
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=oneshield
DB_USERNAME=oneshield
DB_PASSWORD=STRONG_DB_PASSWORD_HERE
DB_ROOT_PASSWORD=STRONG_ROOT_PASSWORD_HERE

# Session
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_DOMAIN=.oneshieldx.com

# Queue
QUEUE_CONNECTION=redis

# Cache
CACHE_STORE=redis

# Redis (container tên 'redis')
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# OneShield — CORS: liệt kê domain money sites, ngăn cách bởi dấu phẩy
ONESHIELD_CORS_ORIGINS=https://shop1.com,https://shop2.com

# Plugin versions
ONESHIELD_CONNECT_VERSION=1.0.0
ONESHIELD_PAYGATES_VERSION=1.0.0
```

> **Lưu ý:** `DB_ROOT_PASSWORD` không có trong `.env.example` mặc định —  
> `docker-compose.yml` đọc biến này để tạo MySQL root password.  
> Thêm dòng này vào `.env` của bạn.

### 1.4 Build và khởi động containers

```bash
# Build frontend assets TRƯỚC khi build Docker image
# (cần Node 20+ trên VPS hoặc build local rồi copy)

# Option A: Build trên VPS
apt install -y nodejs npm
npm ci
npm run build

# Option B: Build trên máy local, copy dist lên
# (từ máy local)
npm run build
scp -r public/build root@YOUR_VPS_IP:/var/www/oneshield/gateway-panel/public/

# Khởi động containers
docker compose up -d --build
```

### 1.5 Khởi tạo ứng dụng

```bash
# Generate APP_KEY
docker exec oneshield_app php artisan key:generate --force

# Chạy migrations
docker exec oneshield_app php artisan migrate --force

# Tạo symlink storage
docker exec oneshield_app php artisan storage:link

# Cache config/routes cho production
docker exec oneshield_app php artisan config:cache
docker exec oneshield_app php artisan route:cache
docker exec oneshield_app php artisan view:cache
```

### 1.6 Tạo tài khoản Super Admin

```bash
docker exec oneshield_app php artisan tinker --execute="
\App\Models\User::create([
    'name'           => 'Admin',
    'email'          => 'admin@yourdomain.com',
    'password'       => bcrypt('YOUR_STRONG_PASSWORD'),
    'tenant_id'      => 'admin',
    'token_secret'   => bin2hex(random_bytes(32)),
    'is_super_admin' => true,
]);
"
```

Hoặc truy cập `https://admin.oneshieldx.com/account/admin` lần đầu tiên để setup qua UI.

### 1.7 Cấu hình Nginx reverse proxy (nếu VPS đã có Nginx host)

Nếu bạn muốn dùng domain thay vì port 8080, cài Nginx trên host và proxy vào container:

```bash
apt install -y nginx certbot python3-certbot-nginx

nano /etc/nginx/sites-available/oneshield
```

```nginx
server {
    listen 80;
    server_name admin.oneshieldx.com *.oneshieldx.com;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;
    }
}
```

```bash
ln -s /etc/nginx/sites-available/oneshield /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

# Cài SSL wildcard (khuyến nghị dùng DNS challenge)
certbot --nginx -d admin.oneshieldx.com -d oneshieldx.com
```

Sau khi có SSL, cập nhật `.env`:
```dotenv
APP_URL=https://admin.oneshieldx.com
APP_HOST=oneshieldx.com
SESSION_DOMAIN=.oneshieldx.com
```
Rồi chạy lại:
```bash
docker exec oneshield_app php artisan config:cache
```

### 1.8 Kiểm tra deployment

```bash
# Kiểm tra containers đang chạy
docker compose ps

# Xem logs nếu có lỗi
docker compose logs app --tail=50
docker compose logs nginx --tail=20

# Test endpoint
curl -I https://admin.oneshieldx.com/
```

Truy cập `https://admin.oneshieldx.com` — login với tài khoản super admin vừa tạo.

---

## Phần 2 — Đóng gói Plugin để upload

Trước khi setup Shield Site và Money Site, cần đóng gói 2 plugin WordPress thành file `.zip`.

```bash
# Từ thư mục gốc project (máy local)
cd plugins

# Plugin cho Shield Site
zip -r oneshield-connect.zip oneshield-connect/

# Plugin cho Money Site (WooCommerce)
zip -r oneshield-paygates.zip oneshield-paygates/
```

Hoặc download trực tiếp từ Gateway Panel:  
**Settings → Download Plugins** — sau khi upload `.zip` vào `gateway-panel/storage/plugins/`.

### Upload plugin vào Gateway Panel storage

```bash
# Tạo thư mục storage/plugins nếu chưa có
docker exec oneshield_app mkdir -p /var/www/storage/plugins

# Copy từ ngoài vào container
docker cp plugins/oneshield-connect.zip oneshield_app:/var/www/storage/plugins/
docker cp plugins/oneshield-paygates.zip oneshield_app:/var/www/storage/plugins/
```

---

## Phần 3 — Setup Shield Site

**Shield Site** là WordPress site xử lý thanh toán thực tế (PayPal/Stripe). Mỗi shield site cần cài plugin **OneShield Connect**.

### 3.1 Yêu cầu Shield Site

- WordPress 6.0+
- PHP 8.0+
- SSL (HTTPS) bắt buộc — PayPal/Stripe không chấp nhận HTTP
- WooCommerce **không** cần trên shield site
- Tên miền riêng (không dùng subdirectory)

### 3.2 Thêm Shield Site vào Gateway Panel

1. Đăng nhập Gateway Panel → **Shield Sites**
2. Click **Add Site**
3. Điền **Site Name** (vd: `Shield Site 1`) và **Site URL** (vd: `https://shield1.yourdomain.com`)
4. Click **Add Site** → site xuất hiện trong danh sách với **Site ID** và **Authorize Key**

### 3.3 Cài plugin OneShield Connect trên Shield Site

1. Vào WordPress Admin của shield site → **Plugins → Add New → Upload Plugin**
2. Upload file `oneshield-connect.zip`
3. **Activate** plugin
4. Vào **Settings → OneShield Connect**

Điền vào form:

| Field | Giá trị |
|-------|---------|
| **Gateway Panel URL** | `https://admin.oneshieldx.com` |
| **Token Secret** | Copy từ Gateway Panel → **Settings → Token Secret** |

5. Click **Connect Now**
6. Thành công sẽ hiện: `Connected successfully! Site ID: X`

### 3.4 Cấu hình Payment Credentials trên Gateway Panel

Sau khi connect, quay lại Gateway Panel → **Shield Sites** → click **Settings** trên site vừa thêm.

#### Tab PayPal

| Field | Giá trị |
|-------|---------|
| PayPal Client ID | Client ID từ PayPal Developer Dashboard |
| PayPal Client Secret | Client Secret từ PayPal Developer Dashboard |
| PayPal Mode | `live` (production) hoặc `sandbox` (test) |
| Activation | `Yes` |
| Income Limit | Tổng tiền tối đa nhận trong chu kỳ (0 = không giới hạn) |
| Max Amount Per Order | Số tiền tối đa 1 đơn hàng (0 = không giới hạn) |

#### Tab Stripe

| Field | Giá trị |
|-------|---------|
| Stripe Public Key | `pk_live_...` từ Stripe Dashboard |
| Stripe Secret Key | `sk_live_...` từ Stripe Dashboard |
| Stripe Mode | `live` |
| Activation | `Yes` |
| Stripe Webhook Signing Secret | `whsec_...` (xem bước 3.5) |
| Webhook URL | Copy URL hiển thị trong panel |

#### Spin Settings (chung cho cả PayPal và Stripe)

| Field | Giá trị |
|-------|---------|
| Receive Cycle | `monthly` — reset limit mỗi tháng |

Click **Save Settings**.

### 3.5 Đăng ký Webhook trên Stripe Dashboard

1. Vào [dashboard.stripe.com](https://dashboard.stripe.com) → **Developers → Webhooks → Add endpoint**
2. Endpoint URL: copy từ Gateway Panel Settings tab Stripe → **Webhook URL**  
   Format: `https://admin.oneshieldx.com/api/webhook/stripe/{site_id}`  
   Thay `{site_id}` bằng ID số của shield site (xem trong URL khi mở Settings)
3. Events cần lắng nghe:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `charge.refunded`
4. Sau khi tạo, copy **Signing secret** (`whsec_...`) → paste vào trường **Stripe Webhook Signing Secret** trên Gateway Panel

### 3.6 Đăng ký Webhook trên PayPal Dashboard

1. Vào [developer.paypal.com](https://developer.paypal.com) → **Apps & Credentials → chọn app → Webhooks**
2. Webhook URL: `https://admin.oneshieldx.com/api/webhook/paypal/{site_id}`
3. Events cần chọn:
   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.DENIED`
   - `PAYMENT.ORDER.CANCELLED`

### 3.7 Kiểm tra kết nối Shield Site

Trên Gateway Panel → **Shield Sites** → click **Check** bên cạnh site.  
Nếu heartbeat hiện màu xanh → site đang hoạt động bình thường.

---

## Phần 4 — Setup Money Site (WooCommerce)

**Money Site** là WooCommerce store của bạn — nơi khách hàng mua hàng.

### 4.1 Yêu cầu Money Site

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+

### 4.2 Cài plugin OneShield Paygates

1. WordPress Admin → **Plugins → Add New → Upload Plugin**
2. Upload `oneshield-paygates.zip`
3. **Activate** plugin

### 4.3 Lấy Gateway Token

Trên Gateway Panel → **Settings → Gateway Tokens**:
- Nếu chưa có token, click **Create Token**, đặt tên (vd: `My WooCommerce Store`)
- Copy token xuất hiện trong banner xanh (chỉ hiện một lần)

### 4.4 Cấu hình Payment Gateway trong WooCommerce

1. WordPress Admin → **WooCommerce → Settings → Payments**
2. Bật **OneShield PayPal** và/hoặc **OneShield Stripe**
3. Click **Manage** trên từng gateway, điền:

| Field | Giá trị |
|-------|---------|
| **Gateway Panel URL** | `https://admin.oneshieldx.com` |
| **Gateway Token** | Token vừa copy ở bước 4.3 |
| **Group ID** *(tuỳ chọn)* | ID nhóm Shield Sites nếu dùng nhiều nhóm |

4. Lưu settings

### 4.5 Test luồng thanh toán

1. Tạo đơn hàng test trên WooCommerce (dùng PayPal sandbox / Stripe test card)
2. Chọn OneShield PayPal hoặc OneShield Stripe
3. Iframe thanh toán xuất hiện → điền thông tin test
4. Sau khi thanh toán → kiểm tra:
   - WooCommerce order status = `Processing`
   - Gateway Panel → **Transactions** → có bản ghi mới với status `completed`
   - Shield site heartbeat vẫn xanh

**Stripe test card:** `4242 4242 4242 4242` · Any future exp · Any CVC  
**PayPal sandbox:** dùng tài khoản sandbox từ developer.paypal.com

---

## Phần 5 — Vận hành & Bảo trì

### Cập nhật code

```bash
cd /var/www/oneshield/gateway-panel
git pull

# Rebuild nếu có thay đổi PHP
docker compose up -d --build app

# Rebuild frontend nếu có thay đổi Vue/CSS
npm run build
docker compose restart nginx

# Chạy migration mới nếu có
docker exec oneshield_app php artisan migrate --force

# Clear cache sau update
docker exec oneshield_app php artisan config:cache
docker exec oneshield_app php artisan route:cache
docker exec oneshield_app php artisan view:cache
```

### Backup database

```bash
docker exec oneshield_db mysqldump \
  -u oneshield -pSTRONG_DB_PASSWORD_HERE \
  oneshield > backup_$(date +%Y%m%d).sql
```

### Xem logs

```bash
# App logs (Laravel)
docker exec oneshield_app tail -f /var/www/storage/logs/laravel.log

# Queue worker logs
docker compose logs horizon --tail=100 -f

# Nginx access logs
docker compose logs nginx --tail=50
```

### Restart services

```bash
# Restart tất cả
docker compose restart

# Restart chỉ app
docker compose restart app

# Restart queue worker
docker compose restart horizon
```

---

## Tóm tắt luồng hoạt động

```
Khách hàng checkout
       ↓
Money Site (WooCommerce + Paygates plugin)
       ↓  POST /api/paygates/get-site  [token auth]
Gateway Panel (Laravel)
       ↓  chọn shield site phù hợp (spin limits, group, gateway)
       ↓  trả về iframe_url
Money Site
       ↓  render iframe trỏ đến Shield Site
Shield Site (WordPress + Connect plugin)
       ↓  thanh toán PayPal / Stripe trực tiếp
       ↓  postMessage kết quả về Money Site
Money Site
       ↓  POST /api/paygates/confirm  [token auth]
Gateway Panel
       ↓  ghi transaction = completed
       ↓  webhook từ PayPal/Stripe xác nhận lại
WooCommerce order = Processing ✓
```
