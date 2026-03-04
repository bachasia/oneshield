# VPS UFW + Nginx Quick Fix (OneShield)

Use this when UFW shows:

`ERROR: Could not find a profile matching 'Nginx Full'`

---

## Why this happens

`Nginx Full` is an **application profile** provided by Nginx package.

If Nginx is not installed yet (or profile is not loaded), UFW cannot find it.

---

## Fast safe fix (works even without Nginx profile)

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw status verbose
```

This is enough for production traffic.

---

## If you want to use `Nginx Full` profile

Install Nginx first:

```bash
sudo apt update
sudo apt install -y nginx
```

Then verify available profiles:

```bash
sudo ufw app list
```

Apply profile:

```bash
sudo ufw allow 'Nginx Full'
sudo ufw status verbose
```

---

## Recommended production firewall for OneShield

Allow only:

- SSH: `22/tcp`
- HTTP: `80/tcp`
- HTTPS: `443/tcp`

Do **not** expose publicly:

- `3306` (MySQL)
- `6379` (Redis)
- `8080` (internal app/nginx container port)

---

## One-time hardened command set

```bash
sudo ufw --force reset
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable
sudo ufw status verbose
```

---

## Verification checklist

- [ ] SSH still accessible after UFW changes
- [ ] `curl -I http://YOUR_DOMAIN` returns response
- [ ] `curl -I https://YOUR_DOMAIN` returns response
- [ ] `ufw status verbose` only shows expected ports
