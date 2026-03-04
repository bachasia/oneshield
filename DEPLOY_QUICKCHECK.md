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

## 4) Rebuild/restart services (tranh 502 do app IP thay doi)

```bash
# Khong restart app + nginx dong thoi trong production
# Lam theo thu tu: app -> nginx -> horizon
docker compose up -d --build --force-recreate app

# Cho app san sang
until [ "$(docker inspect -f '{{.State.Health.Status}}' oneshield_app 2>/dev/null)" = "healthy" ]; do
  sleep 2
done

docker compose up -d --force-recreate nginx
docker compose up -d --build --force-recreate horizon

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
curl -I http://127.0.0.1:8080
curl -I https://admin.oneshieldx.com/
docker exec oneshield_app php artisan tinker --execute="dump(url('/admin'));"
docker compose logs app --tail=80
docker compose logs nginx --tail=80
```

Ket qua mong doi:

- `curl -I` tra ve `200` hoac `302` hop le.
- `curl -I http://127.0.0.1:8080` phai khong con `502`.
- `url('/admin')` phai la `https://admin.oneshieldx.com/admin`.
- Khong co loi `500` moi trong logs.

## 7) Neu loi nhanh

```bash
docker exec oneshield_app php artisan optimize:clear

# 502 thuong do nginx giu upstream app IP cu
docker compose up -d --force-recreate app
until [ "$(docker inspect -f '{{.State.Health.Status}}' oneshield_app 2>/dev/null)" = "healthy" ]; do
  sleep 2
done
docker compose up -d --force-recreate nginx

docker compose logs app --tail=200
docker compose logs nginx --tail=200
```

## 8) Security quick checks

- Khong de `ONESHIELD_CORS_ORIGINS=*` tren production.
- Cloudflare SSL mode: `Full (strict)`.
- Khong expose cong `3306/6379` ra Internet neu khong can.
