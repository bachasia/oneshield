# OneShield Local Dev Setup

Hướng dẫn này dùng để chạy OneShield trên máy dev/test khác một cách nhanh nhất.

Scope:
- chạy `gateway-panel` bằng Docker Compose
- tạo super admin local
- tạo tenant test local
- test admin panel và tenant panel

---

## 1. Yêu cầu máy

- Git
- Docker Desktop hoặc Docker Engine + Compose plugin
- Node.js 20+
- npm

Không bắt buộc cài PHP local vì app chạy trong Docker.

---

## 2. Clone source

```bash
git clone https://github.com/bachasia/oneshield.git
cd oneshield/gateway-panel
```

---

## 3. Tạo file `.env`

```bash
cp .env.example .env
```

Với môi trường local cơ bản, dùng các giá trị này trong `gateway-panel/.env`:

```dotenv
APP_NAME=OneShield
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_HOST=oneshieldx.com

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=oneshield
DB_USERNAME=oneshield
DB_PASSWORD=oneshield_secret
DB_ROOT_PASSWORD=root_secret

SESSION_DRIVER=database
SESSION_DOMAIN=.oneshieldx.com

CACHE_STORE=redis
QUEUE_CONNECTION=database

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
```

Ghi chú:
- `APP_HOST=oneshieldx.com` dùng cho multi-tenant/subdomain logic
- `SESSION_DOMAIN=.oneshieldx.com` giúp test subdomain local dễ hơn
- nếu chỉ test bằng `localhost`, app vẫn chạy bình thường

---

## 4. Khởi động Docker

Trong thư mục `gateway-panel`:

```bash
docker compose up -d --build
```

Containers chính:
- `oneshield_app`
- `oneshield_nginx`
- `oneshield_db`
- `oneshield_redis`
- `oneshield_horizon`

Kiểm tra:

```bash
docker compose ps
```

---

## 5. Cài dependencies + migrate + build

Chạy các lệnh sau trong container app:

```bash
docker exec oneshield_app composer install
docker exec oneshield_app php artisan key:generate
docker exec oneshield_app php artisan migrate --seed
npm install
npm run build
```

Nếu muốn clear cache sau đó:

```bash
docker exec oneshield_app php artisan config:clear
docker exec oneshield_app php artisan route:clear
docker exec oneshield_app php artisan view:clear
```

---

## 6. Mở app local

Mặc định Nginx map ra:

- `http://localhost:8080`

Admin panel local:

- `http://localhost:8080/admin`

Nếu DB chưa có user nào, app sẽ redirect bạn sang:

- `http://localhost:8080/account/admin`

Tại đây tạo tài khoản đầu tiên. User đầu tiên sẽ là `super admin`.

---

## 7. Tạo super admin thủ công (nếu cần)

Nếu muốn tạo nhanh bằng SQL:

```bash
docker exec oneshield_db mysql -uoneshield -poneshield_secret -D oneshield
```

Sau đó insert user hoặc dùng UI `/account/admin` là dễ nhất.

Khuyến nghị: dùng UI setup thay vì insert SQL thủ công.

---

## 8. Tạo tenant test local

Sau khi login super admin:

- vào `http://localhost:8080/admin`
- mở `Tenants`
- chọn `New Tenant`

Ví dụ tenant test:
- Name: `Demo Tenant`
- Email: `demo@oneshieldx.com`
- Password: `Demo@12345`
- Tenant ID: `demo`
- Plan: `Trial`

Sau khi tạo xong, tenant sẽ có subdomain logic là:

- `demo.oneshieldx.com`

---

## 9. Test local bằng localhost

Nếu chỉ cần code UI/backend nhanh, bạn có thể dùng luôn:

- admin: `http://localhost:8080/admin`
- tenant login: `http://localhost:8080`

Lưu ý:
- super admin login xong sẽ vào `/admin`
- tenant thường login xong sẽ vào `/dashboard`
- super admin không được vào tenant panel trực tiếp, chỉ vào qua impersonation

---

## 10. Test local đúng kiểu subdomain

Để test đúng multi-tenant, thêm vào file hosts của máy:

Mac/Linux: `/etc/hosts`

```txt
127.0.0.1 admin.oneshieldx.com
127.0.0.1 demo.oneshieldx.com
```

Sau đó mở:

- `http://admin.oneshieldx.com:8080/admin`
- `http://demo.oneshieldx.com:8080/dashboard`

Ghi chú:
- `admin` là subdomain reserved cho super admin
- tenant hợp lệ ví dụ `demo`, `shop1`, `client-a`

---

## 11. Impersonate flow

Từ admin panel:

- vào `Tenants`
- mở tenant detail
- bấm `Login as tenant`

Khi đó:
- bạn sẽ vào tenant panel bằng session impersonation
- tenant UI có badge vàng báo đang impersonating
- bấm `Stop impersonating` để quay lại admin

---

## 12. Tài khoản test hiện tại

Nếu bạn import DB giống máy hiện tại thì có thể dùng:

- Super admin:
  - email: `me@bach.asia`
  - password: mật khẩu hiện có của bạn

- Demo tenant:
  - email: `demo@oneshieldx.com`
  - password: `Demo@12345`

Nếu là máy mới + DB mới thì bạn cần tự tạo lại qua UI.

---

## 13. Các lệnh hay dùng

Chạy lại app:

```bash
docker compose up -d
```

Xem logs:

```bash
docker compose logs app --tail=100
docker compose logs nginx --tail=100
docker compose logs db --tail=100
```

Vào shell app container:

```bash
docker exec -it oneshield_app sh
```

Chạy artisan:

```bash
docker exec oneshield_app php artisan migrate
docker exec oneshield_app php artisan test
docker exec oneshield_app php artisan config:clear
```

Build frontend:

```bash
npm install
npm run build
```

---

## 14. Nếu bị lỗi

### `403 Access denied` ở `/admin`

Nguyên nhân:
- user đang login không phải `super admin`

Cách xử lý:
- tạo super admin qua `http://localhost:8080/account/admin`
- hoặc login đúng tài khoản admin

### Super admin vào `/dashboard` bị redirect về `/admin`

Đây là hành vi đúng.
Super admin chỉ được vào tenant panel qua impersonation.

### Subdomain local không chạy

Kiểm tra:
- đã thêm `/etc/hosts`
- `APP_HOST=oneshieldx.com`
- đã login lại sau khi đổi `SESSION_DOMAIN`

### Frontend không cập nhật

Chạy lại:

```bash
npm run build
docker exec oneshield_app php artisan view:clear
docker exec oneshield_app php artisan config:clear
```

---

## 15. Luồng khuyến nghị khi làm việc trên máy mới

```bash
git clone https://github.com/bachasia/oneshield.git
cd oneshield/gateway-panel
cp .env.example .env
docker compose up -d --build
docker exec oneshield_app composer install
docker exec oneshield_app php artisan key:generate
docker exec oneshield_app php artisan migrate --seed
npm install
npm run build
```

Sau đó:
- mở `http://localhost:8080/account/admin`
- tạo super admin
- vào `http://localhost:8080/admin`
- tạo tenant test `demo`
