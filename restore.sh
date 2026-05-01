#!/usr/bin/env bash
# Restaure le fichier original sauvegardé par apply_patch.py
set -euo pipefail

TARGET="${1:-/var/www/html/plugins/veolia_eau/core/class/veolia_eau_process.class.php}"
BACKUP="${TARGET}.before_TR_PATCH"

if [[ ! -f "$BACKUP" ]]; then
  echo "ERREUR : backup introuvable : $BACKUP" >&2
  exit 1
fi

cp "$BACKUP" "$TARGET"
echo "Restauré depuis $BACKUP"
php -l "$TARGET"
