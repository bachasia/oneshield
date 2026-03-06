# Ke hoach trien khai Checkout Session theo `checkout_id`

## Muc tieu

Thay the viec truyen nhieu query params nhay cam (`amount`, `currency`, `order_id`, `mode`, `descriptor`...) tren URL iframe checkout bang co che tham chieu ngan gon qua `checkout_id`.

Muc tieu cu the:

- URL iframe toi gian: `/?fe-checkout=1&checkout_id=<id>`
- Du lieu thanh toan duoc luu va xac minh phia server
- Cho phep cap nhat du lieu don hang truoc khi nguoi dung bam thanh toan
- Giam nguy co parameter tampering, log leak, stale data

---

## Hien trang can thay doi

- `gateway-panel/app/Services/SiteRouterService.php`
  - Dang build URL voi day du query params thong qua `buildIframeUrl(...)`.
- `plugins/oneshield-connect/oneshield-connect.php`
  - Dang doc checkout context tu `$_GET` (`gateway`, `order_id`, `token`).
- `plugins/oneshield-connect/inc/gateway.php`
  - Dang build iframe URL placeholder theo query params.

---

## Kien truc de xuat (Cach 1)

## 1) Tao `checkout_session` tren Gateway Panel

Khi money site dat/prepare order, panel tao 1 ban ghi checkout session va tra ve `checkout_id`.

Ban ghi session nen chua:

- `id` (UUID/ULID)
- `user_id`, `site_id`, `gateway`
- `order_ref` (ma order ben money site)
- `currency`, `amount_minor`, `amount_display`
- `mode`, `capture_method`, `enable_wallets`
- `descriptor`, `description_format`
- `billing_snapshot` (JSON encrypt)
- `status`: `created | processing | completed | expired | cancelled`
- `expires_at` (vd 15-30 phut)
- `idempotency_key`
- `meta` (JSON)

## 2) Iframe URL chi con `checkout_id`

Thay vi URL dang dai, tra ve:

`https://shield-site/?fe-checkout=1&checkout_id=<id>`

## 3) Shield Site (WP plugin) resolve context bang `checkout_id`

Khi request vao `?fe-checkout=1&checkout_id=...`:

1. Plugin goi API panel: `GET /api/checkout-sessions/{checkout_id}`
2. Panel xac thuc session (`exists`, `not expired`, `site khop`, `status hop le`)
3. Plugin nhan payload da ky thuat (khong tin du lieu tu browser)
4. Plugin render Stripe/PayPal checkout theo payload

## 4) Truoc khi confirm thanh toan: re-validate

Tai endpoint place-order/confirm:

- Re-fetch session theo `checkout_id`
- Re-check amount/currency trang thai don hang moi nhat
- Neu lech amount do cart/thue/shipping thay doi: update session + tao/refresh intent
- Dung idempotency key theo `checkout_id` de tranh double charge

## 5) Hoan tat va dong session

Khi thanh toan thanh cong (webhook-first):

- Mark `checkout_session.status = completed`
- Luu lien ket `transaction_id`, `stripe_payment_intent_id`
- Khoa session khong cho dung lai (single-use)

---

## Lo trinh trien khai theo phase

## Phase 0 - Chuan bi schema va API

1. Tao migration `checkout_sessions`.
2. Tao model/service/repository cho checkout session.
3. Tao API:
   - `POST /api/checkout-sessions` (create)
   - `GET /api/checkout-sessions/{id}` (resolve)
   - `POST /api/checkout-sessions/{id}/refresh` (optional)
   - `POST /api/checkout-sessions/{id}/complete` (internal/webhook flow)
4. Them cron cleanup session het han.

## Phase 1 - Dual mode (backward compatible)

1. Panel van ho tro URL cu + URL moi.
2. Plugin shield-site uu tien `checkout_id`; neu khong co thi fallback query params cu.
3. Feature flag:
   - `CHECKOUT_ID_ENABLED=true/false`
   - rollout theo tenant/site.

## Phase 2 - Chuyen hoan toan sang `checkout_id`

1. Tat sinh query params nhay cam trong `buildIframeUrl(...)`.
2. Plugin bo parser query params cu (sau thoi gian deprecation).
3. Cap nhat test + docs + monitoring.

---

## Mapping file du kien se sua

### Gateway Panel (Laravel)

- `gateway-panel/app/Services/SiteRouterService.php`
  - Sua `buildIframeUrl()` de chi tra `fe-checkout=1&checkout_id=`.
- `gateway-panel/routes/api.php`
  - Them endpoints checkout-session.
- `gateway-panel/app/Http/Controllers/Api/...`
  - Them controller xu ly create/resolve/refresh session.
- `gateway-panel/app/Models/...`
  - Them model `CheckoutSession`.
- `gateway-panel/database/migrations/...`
  - Them bang `checkout_sessions`.

### Shield Site Plugin (WordPress)

- `plugins/oneshield-connect/oneshield-connect.php`
  - Doc `checkout_id` thay cho bo query params cu.
- `plugins/oneshield-connect/checkout/stripe.php`
  - Render theo payload resolve tu panel.
- `plugins/oneshield-connect/checkout/paypal.php`
  - Tuong tu stripe.
- `plugins/oneshield-connect/inc/gateway.php`
  - Sua helper build URL de dung `checkout_id`.

### Paygates Plugin (Money Site)

- `plugins/oneshield-paygates/...`
  - Goi panel de tao checkout session.
  - Nhan `checkout_id` va iframe_url moi.

---

## Yeu cau bao mat

- `checkout_id` phai random manh (UUIDv4/ULID + entropy cao).
- Session ngan han (15-30 phut).
- Single-use sau khi completed.
- Rate limit endpoint resolve.
- Khong log payload nhay cam (billing, token).
- Neu can chia se qua nhieu service, co the them chu ky HMAC cho response payload.

---

## Xu ly thay doi thong tin truoc khi bam Mua

Tinh huong thay doi so luong, dia chi, shipping, tax:

1. Money site cap nhat order draft.
2. Goi `refresh` hoac tao moi checkout session.
3. Panel update amount/currency moi.
4. Khi confirm Stripe, server tiep tuc re-validate lan cuoi.

Nguyen tac bat buoc: amount dung de tao/capture thanh toan luon do server quyet dinh, khong tin browser.

---

## Test plan

## Unit tests

- Tao session, het han, reuse, idempotency.
- Resolve session sai site/sai tenant bi chan.
- Session completed khong duoc thanh toan lai.

## Integration tests

- End-to-end: create session -> open iframe -> pay success -> webhook -> mark completed.
- Refresh amount truoc khi pay.
- Retry/back/refresh trinh duyet khong tao duplicate charge.

## Security tests

- Thu doan replay `checkout_id` cu.
- Thu dung `checkout_id` cua site khac.
- Thu tampering payload client-side.

---

## Monitoring & rollout

- Them metrics:
  - `checkout_session.create.success/fail`
  - `checkout_session.resolve.success/fail`
  - `checkout_session.expired`
  - `payment.duplicate_prevented`
- Dashboard canh bao khi fail rate resolve > nguong.
- Rollout 10% -> 50% -> 100% tenant.

---

## Tieu chi hoan thanh

- Khong con `amount/currency/order_id/token` tren iframe URL public.
- Ty le thanh toan thanh cong khong giam so voi baseline.
- Khong phat sinh duplicate charge do refresh/back.
- Toan bo flow co webhook reconciliation day du.
