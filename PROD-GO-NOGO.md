# OneShield Production Go/No-Go Checklist

Use this checklist before any production test or go-live.

Rule:
- **Go** only when all critical items pass.
- **No-Go** if any blocking item fails.

---

## 1) Domain, DNS, and SSL

- [ ] `admin.oneshieldx.com` resolves to the correct VPS IP.
- [ ] `*.oneshieldx.com` wildcard DNS resolves correctly.
- [ ] SSL certificate is valid for `admin.oneshieldx.com` and tenant subdomains.
- [ ] Browser shows secure lock (no TLS warning).

Quick checks:

```bash
dig admin.oneshieldx.com +short
dig demo.oneshieldx.com +short
curl -I https://admin.oneshieldx.com
```

---

## 2) Nginx and App Routing

- [ ] Nginx forwards host header correctly.
- [ ] `admin.oneshieldx.com` opens super admin panel.
- [ ] `{tenant}.oneshieldx.com` routes to same Laravel app.
- [ ] Unknown tenant subdomain returns correct error behavior (404/blocked).

---

## 3) Laravel Production Environment

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL=https://admin.oneshieldx.com`
- [ ] `APP_HOST=oneshieldx.com`
- [ ] `SESSION_DOMAIN=.oneshieldx.com`
- [ ] `APP_KEY` is set and stable.

---

## 4) Secrets and Credentials

- [ ] DB credentials are correct on server.
- [ ] Redis credentials/config are correct.
- [ ] Stripe/PayPal secrets are set securely.
- [ ] No secrets are committed in git history or repo files.

---

## 5) Database Readiness

- [ ] Full DB backup taken before deployment.
- [ ] `php artisan migrate --force` completed successfully.
- [ ] `plans` data exists (trial/start/pro/enterprise).
- [ ] No migration/index errors in logs.

Quick checks:

```bash
php artisan migrate:status
php artisan tinker --execute="App\\Models\\Plan::count();"
```

---

## 6) Cache, Queue, and Workers

- [ ] Redis is healthy.
- [ ] Queue worker/Horizon is running.
- [ ] Failed jobs table not growing unexpectedly.
- [ ] App cache/config/route caches are in expected state.

Quick checks:

```bash
php artisan queue:monitor
php artisan queue:failed
```

---

## 7) Files, Storage, and Assets

- [ ] `storage` and `bootstrap/cache` are writable.
- [ ] `php artisan storage:link` completed.
- [ ] Frontend assets built and served (no 404 for build files).
- [ ] App UI loads without JS/CSS console errors.

---

## 8) Access Control and Roles

- [ ] Super admin can access `/admin`.
- [ ] Non-admin user cannot access admin routes (403 expected).
- [ ] Super admin cannot use tenant panel directly (unless impersonating).
- [ ] Login redirects are correct by role.

---

## 9) Multi-tenant Isolation

- [ ] Tenant A cannot view/edit Tenant B resources.
- [ ] Shield sites, tokens, transactions are scoped by tenant.
- [ ] Subdomain resolution maps to correct tenant record.

---

## 10) Subscription and Plan Enforcement

- [ ] Tenant with active/trial plan can use panel.
- [ ] Expired/suspended tenant is blocked with proper message page.
- [ ] Shield site limit enforcement works (block + upgrade message).
- [ ] Plan usage indicators in UI are accurate.

---

## 11) Impersonation Flow

- [ ] Admin can impersonate tenant from admin panel.
- [ ] Tenant UI clearly shows impersonation notice.
- [ ] Stop impersonation returns to admin account safely.
- [ ] No privilege escalation path from tenant context.

---

## 12) Payment and Webhook End-to-End

- [ ] Stripe webhook endpoint reachable from Stripe.
- [ ] PayPal webhook/IPN endpoint reachable and verified.
- [ ] Valid payment updates transaction status correctly.
- [ ] Invalid/spoofed webhook is rejected.
- [ ] Retry/idempotency behavior is acceptable.

---

## 13) Observability and Recovery

- [ ] Application logs available and readable.
- [ ] Log rotation configured.
- [ ] Basic alerting exists (5xx spike, queue failures, service down).
- [ ] Rollback plan documented and tested once.

---

## 14) Final Smoke Test (Mandatory)

Run this sequence on production-like environment:

- [ ] Super admin login works.
- [ ] Create a new tenant.
- [ ] Login as tenant (impersonate).
- [ ] Create shield site until plan limit reached.
- [ ] Verify limit block and messaging.
- [ ] Suspend tenant and confirm blocked page.
- [ ] Unsuspend tenant and confirm access restored.
- [ ] Stop impersonation and return to admin.

---

## Decision

### GO

- [ ] All items above pass.

### NO-GO (any one is enough)

- [ ] SSL/domain routing incorrect.
- [ ] Webhook verification fails.
- [ ] Tenant data isolation breach.
- [ ] Queue/worker down with no recovery.
- [ ] Plan enforcement can be bypassed.

---

## Sign-off

- Checked by: ____________________
- Date/time: _____________________
- Environment: ___________________
- Decision: GO / NO-GO
