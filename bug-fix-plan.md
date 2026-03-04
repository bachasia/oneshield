# OneShield Bug Fix Plan

Generated: 2026-03-03  
Source: Comprehensive codebase audit

---

## CRITICAL

- [x] **BUG-01** `checkout.js:50` — `osOrderId` luôn = 0, toàn bộ payment confirm flow broken
- [x] **BUG-02** `class-os-stripe.php:54` / `class-os-paypal.php:52` — `gateway` field thiếu trong `process_payment` response → `confirmPayment` gửi gateway = 'unknown'
- [x] **BUG-03** `routes/web.php:46-47` — Route conflict: `transactions/export/csv` bị che bởi `transactions/{transaction}`

## HIGH

- [x] **BUG-04** `class-os-payment-base.php:120` — `site_id` vs `transaction_id` nhầm lẫn trong confirm payload
- [x] **BUG-05** `routes/web.php:37` — `sites.create` / `sites.edit` methods không tồn tại trong ShieldSiteController
- [x] **BUG-06** `Settings/Index.vue:192` — `gateway_tokens` prop được nhận nhưng UI không render, không thể quản lý tokens

## MEDIUM

- [x] **BUG-07** `order.php:13` — `osc_create_tracking_order()` defined nhưng không được gọi trong checkout
- [x] **BUG-08** `settings.php:136` — `#osc-last-heartbeat` element luôn hiện "checking...", không có JS update
- [x] **BUG-09** `class-os-ipn-handler.php:16` — IPN webhook handler thiếu signature verification
- [x] **BUG-10** `WebhookController.php:171` — PayPal IPN fail-open khi network error (security risk)

## LOW

- [x] **BUG-11** `Sites/Index.vue:215` — Drag handle hiển thị nhưng không có sortable logic
- [x] **BUG-12** `Settings/Index.vue:205` / `Sites/Index.vue:684` — Copy functions thiếu success feedback
- [x] **BUG-13** `.env.example` — Thiếu tất cả `ONESHIELD_*` environment variables
- [x] **BUG-14** `SiteRouterService.php:101` — Airwallex gateway fallback về Stripe spin limits

---

## Phase 2 Features

- [x] **FEAT-01** Drag-to-reorder Shield Sites — `sort_order` column + `PATCH /sites/reorder` API + native HTML5 drag events in `Sites/Index.vue`
- [x] **FEAT-02** Gateway token create/revoke — `POST /settings/tokens` + `DELETE /settings/tokens/{token}` + full UI in `Settings/Index.vue` + `new_token` flash via Inertia shared props

---

## Fix Log

| Bug | Fixed | Notes |
|-----|-------|-------|
| BUG-01 | ✅ | checkout.js: osOrderId = data.wc_order_id; nonce dùng biến riêng |
| BUG-02 | ✅ | class-os-stripe.php + class-os-paypal.php: thêm wc_order_id + gateway vào response |
| BUG-03 | ✅ | routes/web.php đã có thứ tự đúng + explicit routes |
| BUG-04 | ✅ | class-os-payment-base.php: đổi param thành $os_site_id; oneshield-paygates.php: lấy site_id từ order meta _os_site_id |
| BUG-05 | ✅ | routes/web.php đã dùng explicit routes, không có create/edit |
| BUG-06 | ✅ | Settings/Index.vue: thêm Gateway Tokens section, hiển thị name/status/last_used; confirm dialog chuyển sang Vue |
| BUG-07 | ✅ | order.php: thêm AJAX handlers osc_create_tracking + osc_complete_tracking; checkout/stripe.php + paypal.php: gọi complete_tracking trước postMessage |
| BUG-08 | ✅ | settings.php: render heartbeat server-side bằng PHP từ last_heartbeat option, loại bỏ JS dependency |
| BUG-09 | ✅ | class-os-ipn-handler.php: thêm verify_signature() dùng HMAC-SHA256 + timestamp replay protection |
| BUG-10 | ✅ | WebhookController.php: đổi fail-open → fail-closed; PayPal retry tự động sau khi connectivity được restore |
| BUG-11 | ✅ | Sites/Index.vue: xóa fake drag handle ⊕, thay bằng comment Phase 2 |
| BUG-12 | ✅ | Sites/Index.vue + Settings/Index.vue: thêm toast notification sau copy thành công |
| BUG-13 | ✅ | .env.example: thêm tất cả ONESHIELD_* vars với giá trị default và comments |
| BUG-14 | ✅ | SiteRouterService.php: Airwallex bypass spin limits (no DB columns yet) thay vì fallback về Stripe limits |
