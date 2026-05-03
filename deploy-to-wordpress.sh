#!/usr/bin/env bash
# Copy this plugin tree into the local WordPress plugins directory.
# Requires sudo because /var/www is owned by www-data.
set -euo pipefail
SRC="$(cd "$(dirname "$0")" && pwd)"
DEST="${1:-/var/www/wordpress/wp-content/plugins/feedico-sync}"
sudo rsync -a --delete "$SRC/" "$DEST/"
sudo chown -R www-data:www-data "$DEST"
echo "Deployed $SRC -> $DEST"
