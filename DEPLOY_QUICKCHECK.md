# DEPLOY_QUICKCHECK

Checklist nhanh cho moi lan deploy `gateway-panel` tren VPS (bind mount mode).

## 1) Pull code

```bash
cd /var/www/oneshield/gateway-panel
git pull
```

## 2) Composer deps (chay khi co thay doi `composer.lock`)

```bash
docker compose run --rm app composer install --no-dev --optimize-autoloader
```

## 3) Frontend build (chay khi co thay doi Vue/CSS/JS)

```bash
npm ci
npm run build
```

## 4) Rebuild/restart services

```bash
docker compose up -d --build app nginx horizon
docker compose ps
```

## 5) Laravel post-deploy

```bash
docker exec oneshield_app php artisan migrate --force
docker exec oneshield_app php artisan optimize:clear
docker exec oneshield_app php artisan config:cache
docker exec oneshield_app php artisan route:cache
docker exec oneshield_app php artisan view:cache
```

## 6) Smoke test

```bash
curl -I https://admin.oneshieldx.com/
docker exec oneshield_app php artisan tinker --execute="dump(url('/admin'));"
docker compose logs app --tail=80
docker compose logs nginx --tail=80
```

Ket qua mong doi:

- `curl -I` tra ve `200` hoac `302` hop le.
- `url('/admin')` phai la `https://admin.oneshieldx.com/admin`.
- Khong co loi `500` moi trong logs.

## 7) Neu loi nhanh

```bash
docker exec oneshield_app php artisan optimize:clear
docker compose restart app nginx
docker compose logs app --tail=200
```

## 8) Security quick checks

- Khong de `ONESHIELD_CORS_ORIGINS=*` tren production.
- Cloudflare SSL mode: `Full (strict)`.
- Khong expose cong `3306/6379` ra Internet neu khong can.
