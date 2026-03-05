#!/usr/bin/env bash
# =============================================================================
# OneShield — Production Deploy / Update Script
# Usage:
#   ./deploy.sh                  # normal update (pull + auto-detect what changed)
#   ./deploy.sh --already-pulled # skip git pull (đã pull thủ công rồi)
#   ./deploy.sh --full           # force full rebuild (composer + npm + docker build)
#   ./deploy.sh --skip-npm       # skip frontend build (PHP-only changes)
# =============================================================================

set -euo pipefail

# ── Config ────────────────────────────────────────────────────────────────────
REPO_DIR="/var/www/oneshield"
APP_DIR="$REPO_DIR/gateway-panel"
CONTAINER_APP="oneshield_app"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

log()     { echo -e "${CYAN}[deploy]${NC} $*"; }
ok()      { echo -e "${GREEN}[✓]${NC} $*"; }
warn()    { echo -e "${YELLOW}[!]${NC} $*"; }
fail()    { echo -e "${RED}[✗] $*${NC}"; exit 1; }
section() { echo -e "\n${BOLD}── $* ──────────────────────────────────────────${NC}"; }

FORCE_FULL=false
SKIP_NPM=false
ALREADY_PULLED=false
for arg in "$@"; do
  case $arg in
    --full)           FORCE_FULL=true ;;
    --skip-npm)       SKIP_NPM=true ;;
    --already-pulled) ALREADY_PULLED=true ;;
  esac
done

# ── Verify running as root or with docker access ──────────────────────────────
if ! docker info &>/dev/null; then
  fail "Docker is not running or you don't have permission. Run as root or add user to docker group."
fi

# ── Step 1: Pull latest code ──────────────────────────────────────────────────
section "Step 1: Pull code"
cd "$REPO_DIR"

if [ "$ALREADY_PULLED" = true ]; then
  # Đã pull rồi — so sánh HEAD với commit trước đó (HEAD~1)
  AFTER=$(git rev-parse HEAD)
  BEFORE=$(git rev-parse HEAD~1 2>/dev/null || echo "")
  warn "Skipping git pull (--already-pulled). Detecting changes vs previous commit..."
  if [ -n "$BEFORE" ]; then
    ok "Comparing: $(git rev-parse --short $BEFORE) → $(git rev-parse --short $AFTER)"
  fi
else
  BEFORE=$(git rev-parse HEAD)
  git pull origin main
  AFTER=$(git rev-parse HEAD)

  if [ "$BEFORE" = "$AFTER" ] && [ "$FORCE_FULL" = false ]; then
    warn "No new commits. Use --full to force a full redeploy."
    exit 0
  fi
  ok "Updated: $(git rev-parse --short $BEFORE) → $(git rev-parse --short $AFTER)"
fi

# ── Detect what changed ───────────────────────────────────────────────────────
if [ -n "${BEFORE:-}" ]; then
  CHANGED=$(git diff --name-only "$BEFORE" "$AFTER" 2>/dev/null || echo "")
else
  CHANGED=""
fi

needs_composer=false
needs_npm=false
needs_migrate=false
needs_docker_rebuild=false

if [ "$FORCE_FULL" = true ]; then
  needs_composer=true; needs_npm=true; needs_migrate=true; needs_docker_rebuild=true
else
  echo "$CHANGED" | grep -qE "^gateway-panel/composer\.lock$"          && needs_composer=true
  echo "$CHANGED" | grep -qE "^gateway-panel/docker/"                  && needs_docker_rebuild=true
  echo "$CHANGED" | grep -qE "^gateway-panel/database/migrations/"     && needs_migrate=true
  if [ "$SKIP_NPM" = false ]; then
    echo "$CHANGED" | grep -qE "^gateway-panel/(resources/|vite\.config|package)" && needs_npm=true
  fi
fi

log "composer install : $([ "$needs_composer" = true ] && echo 'YES' || echo 'skip')"
log "npm build        : $([ "$needs_npm" = true ]      && echo 'YES' || echo 'skip')"
log "db migrate       : $([ "$needs_migrate" = true ]  && echo 'YES' || echo 'skip')"
log "docker rebuild   : $([ "$needs_docker_rebuild" = true ] && echo 'YES' || echo 'skip')"

# ── Step 2: Composer (if needed) ──────────────────────────────────────────────
if [ "$needs_composer" = true ]; then
  section "Step 2: Composer install"
  cd "$APP_DIR"
  docker compose run --rm app composer install --no-dev --optimize-autoloader
  ok "Composer done"
else
  log "Step 2: Composer — skipped (composer.lock unchanged)"
fi

# ── Step 3: Frontend build (if needed) ────────────────────────────────────────
if [ "$needs_npm" = true ]; then
  section "Step 3: Frontend build"
  cd "$APP_DIR"
  npm ci
  npm run build
  ok "Frontend build done"
else
  log "Step 3: Frontend build — skipped"
fi

# ── Step 4: Restart containers ────────────────────────────────────────────────
section "Step 4: Restart containers"
cd "$APP_DIR"

if [ "$needs_docker_rebuild" = true ]; then
  log "Rebuilding app image (Dockerfile changed)..."
  docker compose up -d --build --force-recreate app
else
  log "Recreating app container (no image rebuild needed)..."
  docker compose up -d --force-recreate app
fi

log "Waiting for app to become healthy..."
ATTEMPTS=0
until [ "$(docker inspect -f '{{.State.Health.Status}}' $CONTAINER_APP 2>/dev/null)" = "healthy" ]; do
  ATTEMPTS=$((ATTEMPTS + 1))
  if [ $ATTEMPTS -ge 30 ]; then
    fail "App container did not become healthy after 60s. Check logs: docker compose logs app --tail=50"
  fi
  sleep 2
done
ok "App is healthy"

docker compose up -d --force-recreate nginx
ok "Nginx restarted"

if [ "$needs_docker_rebuild" = true ]; then
  docker compose up -d --build --force-recreate horizon
else
  docker compose up -d --force-recreate horizon
fi
ok "Horizon restarted"

# ── Step 5: Laravel post-deploy ───────────────────────────────────────────────
section "Step 5: Laravel post-deploy"
cd "$APP_DIR"

if [ "$needs_migrate" = true ]; then
  log "Running migrations..."
  docker exec $CONTAINER_APP php artisan migrate --force
  ok "Migrations done"
fi

docker exec $CONTAINER_APP php artisan optimize:clear
docker exec $CONTAINER_APP php artisan config:cache
docker exec $CONTAINER_APP php artisan route:cache
docker exec $CONTAINER_APP php artisan view:cache
ok "Cache refreshed"

# ── Step 6: Smoke test ────────────────────────────────────────────────────────
section "Step 6: Smoke test"

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 http://127.0.0.1:8080/ || echo "000")
if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "302" ]; then
  ok "Local HTTP check passed (HTTP $HTTP_CODE)"
else
  warn "Local HTTP returned $HTTP_CODE — check logs below"
fi

GENERATED_URL=$(docker exec $CONTAINER_APP php artisan tinker --execute="echo url('/dashboard');" 2>/dev/null | tail -1)
if echo "$GENERATED_URL" | grep -q "https://"; then
  ok "URL generation OK: $GENERATED_URL"
else
  warn "URL generation may be wrong: $GENERATED_URL"
fi

echo ""
echo -e "${GREEN}${BOLD}Deploy complete.${NC} Commit: $(git -C "$REPO_DIR" rev-parse --short HEAD)"
echo ""
log "Recent app errors (last 20 lines):"
docker compose -f "$APP_DIR/docker-compose.yml" logs app --tail=20 2>/dev/null | grep -i "error\|exception\|fatal" || echo "  (none)"
