# OneShield - Runbook Deploy VPS (Audit + Quy trinh chuan)

> Muc tieu: deploy lai tren VPS muot hon, tranh lap lai cac loi da gap.
> Stack: Laravel 12 + Inertia/Vue 3 + MySQL 8 + Redis 7 + Nginx + Docker Compose

---

## 0) Audit nhanh: da gap gi va da fix gi

### Loi da gap trong lan deploy vua roi

- `vendor/autoload.php` missing khi chay `php artisan key:generate`
  - Nguyen nhan: bind mount `.:/var/www` de len container nhung host chua co `vendor/`.
  - Cach fix: chay `composer install` trong project truoc khi khoi tao Laravel.

- `npm run build` loi Vite `module.enableCompileCache?.()`
  - Nguyen nhan: Node qua cu (Node 12).
  - Cach fix: nang cap Node 20 LTS.

- Frontend/axios goi `http://admin...` trong khi site dang `https://...`
  - Hien tuong: CSP chan request, login/create tenant fail (`AxiosError: Network Error`).
  - Nguyen nhan: chuoi proxy 2 lop (host nginx -> nginx container -> php-fpm) lam mat thong tin `https`.
  - Cach fix:
    - `.env`: `APP_URL` + `ASSET_URL` dung `https`.
    - Host Nginx gui `X-Forwarded-Proto https`, `X-Forwarded-Port 443`.
    - Nginx container truyen lai vao fastcgi: `HTTP_X_FORWARDED_PROTO`, `HTTP_X_FORWARDED_PORT`, `HTTPS on`.
    - Laravel force https trong production (AppServiceProvider).

- Certbot `--nginx` bao plugin khong ton tai
  - Nguyen nhan: thieu plugin certbot nginx.
  - Cach fix: cai `python3-certbot-nginx` hoac dung DNS challenge (Cloudflare) cho wildcard.

- CSP canh bao `static.cloudflareinsights.com/beacon...`
  - Khong pha flow app. Do Cloudflare Browser Insights bi chan boi CSP strict.
  - Cach xu ly: tat Browser Insights hoac whitelisting them domain cloudflareinsights trong CSP.

### Viec can lam ngay sau audit

- Doi lai mat khau DB neu da lo trong log/chat.
- Khong expose `3306`, `6379` ra public neu khong can debug tu xa.

---

## 1) Chuan bi VPS

```bash
ssh root@YOUR_VPS_IP
apt update && apt upgrade -y
apt install -y git curl ca-certificates gnupg lsb-release unzip

# Docker + compose plugin
curl -fsSL https://get.docker.com | sh
apt install -y docker-compose-plugin

docker --version
docker compose version
```

---

## 2) Lay code va tao .env

```bash
cd /var/www
git clone https://github.com/YOUR_ORG/oneshield.git
cd /var/www/oneshield/gateway-panel

cp .env.example .env
nano .env
```

Mau bien quan trong cho production:

```dotenv
APP_NAME=OneShield
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://admin.oneshieldx.com
ASSET_URL=https://admin.oneshieldx.com
APP_HOST=oneshieldx.com

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=oneshield
DB_USERNAME=oneshield
DB_PASSWORD=STRONG_DB_PASSWORD
DB_ROOT_PASSWORD=STRONG_ROOT_PASSWORD

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_DOMAIN=.oneshieldx.com
SESSION_SECURE_COOKIE=true

QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Khong de * o production
ONESHIELD_CORS_ORIGINS=https://shop1.com,https://shop2.com
```

---

## 3) Node 20 + build frontend

> Vite can Node 20+.

```bash
# Neu VPS dang co Node cu, remove de tranh conflict
apt remove -y libnode-dev nodejs npm || true
apt autoremove -y

# Cai Node 20 LTS
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs

node -v
npm -v

cd /var/www/oneshield/gateway-panel
npm ci
npm run build
```

Kiem tra build:

```bash
ls -lah /var/www/oneshield/gateway-panel/public/build
```

---

## 4) Composer + chay containers

```bash
cd /var/www/oneshield/gateway-panel

# Quan trong voi bind mount: host phai co vendor/
docker compose run --rm app composer install --no-dev --optimize-autoloader

docker compose up -d --build
docker compose ps
```

---

## 5) Khoi tao Laravel

```bash
cd /var/www/oneshield/gateway-panel

docker exec oneshield_app php artisan key:generate --force
docker exec oneshield_app php artisan migrate --force
docker exec oneshield_app php artisan db:seed --force
docker exec oneshield_app php artisan storage:link

docker exec oneshield_app php artisan optimize:clear
docker exec oneshield_app php artisan config:cache
docker exec oneshield_app php artisan route:cache
docker exec oneshield_app php artisan view:cache
```

---

## 6) Sửa cac diem bat buoc de tranh HTTP/HTTPS mismatch

### 6.1 AppServiceProvider force https o production

File: `gateway-panel/app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }
    }
}
```

### 6.2 Nginx trong container: pass dung forwarded proto vao PHP

File: `gateway-panel/docker/nginx/default.conf`

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;

        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;

        # Giu scheme tu host nginx
        fastcgi_param HTTP_X_FORWARDED_PROTO $http_x_forwarded_proto;
        fastcgi_param HTTP_X_FORWARDED_PORT  $http_x_forwarded_port;
        fastcgi_param HTTPS on;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Apply lai:

```bash
cd /var/www/oneshield/gateway-panel
docker compose up -d --build app nginx
docker exec oneshield_app php artisan optimize:clear
docker exec oneshield_app php artisan config:cache
```

---

## 7) Nginx host + SSL (Cloudflare)

### 7.1 DNS Cloudflare

- Tao `A` record:
  - `admin` -> `YOUR_VPS_IP`
  - `*` -> `YOUR_VPS_IP` (neu dung wildcard subdomain)

### 7.2 Nginx host reverse proxy

File: `/etc/nginx/sites-available/oneshield`

```nginx
server {
    listen 80;
    server_name admin.oneshieldx.com *.oneshieldx.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name admin.oneshieldx.com *.oneshieldx.com;

    ssl_certificate     /etc/letsencrypt/live/oneshieldx.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/oneshieldx.com/privkey.pem;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto https;
        proxy_set_header   X-Forwarded-Port 443;
        proxy_read_timeout 120s;
    }
}
```

```bash
apt install -y nginx certbot python3-certbot-dns-cloudflare
ln -sf /etc/nginx/sites-available/oneshield /etc/nginx/sites-enabled/oneshield
nginx -t && systemctl reload nginx
```

### 7.3 Wildcard cert bang Cloudflare DNS challenge

```bash
mkdir -p /root/.secrets/certbot
nano /root/.secrets/certbot/cloudflare.ini
```

Noi dung:

```ini
dns_cloudflare_api_token = YOUR_CLOUDFLARE_API_TOKEN
```

```bash
chmod 600 /root/.secrets/certbot/cloudflare.ini

certbot certonly \
  --dns-cloudflare \
  --dns-cloudflare-credentials /root/.secrets/certbot/cloudflare.ini \
  -d oneshieldx.com \
  -d "*.oneshieldx.com"

nginx -t && systemctl reload nginx
certbot renew --dry-run
```

Cloudflare SSL/TLS mode: dat `Full (strict)`.

---

## 8) Tao super admin + upload plugins

### 8.1 Tao super admin

```bash
docker exec oneshield_app php artisan tinker --execute="
\App\Models\User::create([
    'name'           => 'Admin',
    'email'          => 'admin@oneshieldx.com',
    'password'       => bcrypt('YOUR_STRONG_PASSWORD'),
    'tenant_id'      => 'admin',
    'token_secret'   => bin2hex(random_bytes(32)),
    'is_super_admin' => true,
]);
"
```

### 8.2 Dong goi plugin

```bash
cd /var/www/oneshield/plugins
apt install -y zip
zip -r oneshield-connect.zip oneshield-connect/
zip -r oneshield-paygates.zip oneshield-paygates/
```

### 8.3 Upload vao storage/plugins

```bash
cd /var/www/oneshield/gateway-panel
docker exec oneshield_app mkdir -p /var/www/storage/plugins
docker cp ../plugins/oneshield-connect.zip oneshield_app:/var/www/storage/plugins/
docker cp ../plugins/oneshield-paygates.zip oneshield_app:/var/www/storage/plugins/
docker exec oneshield_app ls -lah /var/www/storage/plugins
```

---

## 9) Verify sau deploy

```bash
curl -I https://admin.oneshieldx.com/
docker compose ps
docker compose logs app --tail=100
docker compose logs nginx --tail=100
```

Kiem tra HTTPS generate URL trong app:

```bash
docker exec oneshield_app php artisan tinker --execute="dump(url('/admin'));"
```

Ket qua phai la `https://admin.oneshieldx.com/admin`.

---

## 10) Runbook update cho lan sau (bind mount mode)

```bash
cd /var/www/oneshield/gateway-panel
git pull

# Neu composer.lock thay doi
docker compose run --rm app composer install --no-dev --optimize-autoloader

# Neu frontend thay doi
npm ci
npm run build

docker compose up -d --build app nginx horizon
docker exec oneshield_app php artisan migrate --force
docker exec oneshield_app php artisan optimize:clear
docker exec oneshield_app php artisan config:cache
```

---

## 11) Hardening khuyen nghi

- Dong port public cho DB/Redis (`3306`, `6379`) neu khong can.
- Ban Cloudflare/WAF rule co ban cho bot scan.
- Khong de `ONESHIELD_CORS_ORIGINS=*` tren production.
- Rotate secrets dinh ky: DB password, token secret, API keys.
