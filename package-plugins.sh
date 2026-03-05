#!/usr/bin/env bash
set -euo pipefail

# Package WordPress plugins and place ZIPs into Laravel storage volume
# so /api/plugins/download/{plugin} can serve them.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGINS_DIR="$ROOT_DIR/plugins"
GATEWAY_DIR="$ROOT_DIR/gateway-panel"

CONNECT_SRC="$PLUGINS_DIR/oneshield-connect"
PAYGATES_SRC="$PLUGINS_DIR/oneshield-paygates"

# Main destination used by Storage::download() with local disk
DEST_PRIVATE="$GATEWAY_DIR/storage/app/private/plugins"

# Legacy/fallback destination some runbooks still reference
DEST_LEGACY="$GATEWAY_DIR/storage/plugins"

BUILD_DIR="$ROOT_DIR/.build/plugins"

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "[error] Missing required command: $1"
    exit 1
  }
}

log() {
  printf "[package-plugins] %s\n" "$*"
}

need_cmd zip

[[ -d "$CONNECT_SRC" ]] || { echo "[error] Missing: $CONNECT_SRC"; exit 1; }
[[ -d "$PAYGATES_SRC" ]] || { echo "[error] Missing: $PAYGATES_SRC"; exit 1; }
[[ -d "$GATEWAY_DIR" ]] || { echo "[error] Missing: $GATEWAY_DIR"; exit 1; }

mkdir -p "$BUILD_DIR" "$DEST_PRIVATE" "$DEST_LEGACY"

CONNECT_ZIP="$BUILD_DIR/oneshield-connect.zip"
PAYGATES_ZIP="$BUILD_DIR/oneshield-paygates.zip"

rm -f "$CONNECT_ZIP" "$PAYGATES_ZIP"

log "Creating oneshield-connect.zip"
(
  cd "$PLUGINS_DIR"
  zip -rq "$CONNECT_ZIP" "oneshield-connect" \
    -x "*/.DS_Store" "*/__MACOSX/*" "*/.git/*" "*/node_modules/*"
)

log "Creating oneshield-paygates.zip"
(
  cd "$PLUGINS_DIR"
  zip -rq "$PAYGATES_ZIP" "oneshield-paygates" \
    -x "*/.DS_Store" "*/__MACOSX/*" "*/.git/*" "*/node_modules/*"
)

cp -f "$CONNECT_ZIP" "$DEST_PRIVATE/oneshield-connect.zip"
cp -f "$PAYGATES_ZIP" "$DEST_PRIVATE/oneshield-paygates.zip"

# Keep legacy path in sync (optional but helpful)
cp -f "$CONNECT_ZIP" "$DEST_LEGACY/oneshield-connect.zip"
cp -f "$PAYGATES_ZIP" "$DEST_LEGACY/oneshield-paygates.zip"

chmod 644 "$DEST_PRIVATE/oneshield-connect.zip" "$DEST_PRIVATE/oneshield-paygates.zip"

log "Done. Files ready:"
log "- $DEST_PRIVATE/oneshield-connect.zip"
log "- $DEST_PRIVATE/oneshield-paygates.zip"
log "- $DEST_LEGACY/oneshield-connect.zip"
log "- $DEST_LEGACY/oneshield-paygates.zip"

if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -q '^oneshield_app$'; then
  log "Verifying inside container volume (/var/www/storage/app/private/plugins):"
  docker exec oneshield_app ls -lah /var/www/storage/app/private/plugins || true
fi

cat <<'EOF'

Expected .env values (gateway-panel/.env):
ONESHIELD_CONNECT_DOWNLOAD_URL=storage/plugins/oneshield-connect.zip
ONESHIELD_PAYGATES_DOWNLOAD_URL=storage/plugins/oneshield-paygates.zip

Download endpoints:
/api/plugins/download/connect
/api/plugins/download/paygates
EOF
