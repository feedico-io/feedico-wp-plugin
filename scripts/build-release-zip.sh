#!/usr/bin/env bash
# Build feedico-sync.zip for GitHub Releases (excludes .git, dev scripts noise).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${ROOT}/feedico-sync.zip"
TMP="$(mktemp -d)"

rsync -a \
  --exclude '.git' \
  --exclude '.github' \
  --exclude 'wpreal' \
  --exclude 'deploy-to-wordpress.sh' \
  --exclude '*.zip' \
  "$ROOT/" "$TMP/feedico-sync/"

(cd "$TMP" && zip -qr "$OUT" feedico-sync)
rm -rf "$TMP"
echo "Built $OUT"
