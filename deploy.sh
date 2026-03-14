#!/bin/bash
# deploy.sh – Baut das Plugin und installiert es auf dem Plesk-Server
#
# Erstinstallation:  ./deploy.sh
# Update deployen:   ./deploy.sh
# Anderer Server:    ./deploy.sh root@anderer-server.ch
#
set -euo pipefail

PLESK_HOST="${1:-root@itsieber.ch}"
ZIP_NAME="o365-exit-migrator.zip"
REMOTE_TMP="/tmp/${ZIP_NAME}"

cd "$(dirname "$0")"

echo "▶ Baue ${ZIP_NAME} ..."
rm -f "$ZIP_NAME"
zip -r "$ZIP_NAME" \
    meta.xml \
    version.json \
    _meta/ \
    htdocs/ \
    plib/ \
    -x "*.DS_Store" \
    -q

SIZE=$(du -h "$ZIP_NAME" | cut -f1)
echo "  ✓ ${ZIP_NAME} (${SIZE})"

echo ""
echo "▶ Übertrage zu ${PLESK_HOST} ..."
scp -q "$ZIP_NAME" "${PLESK_HOST}:${REMOTE_TMP}"
echo "  ✓ Übertragen"

echo ""
echo "▶ Installiere auf Plesk ..."
ssh "$PLESK_HOST" "plesk bin extension -i ${REMOTE_TMP} && rm -f ${REMOTE_TMP}"
echo "  ✓ Installiert"

echo ""
echo "✅ Fertig! Plugin ist aktiv auf ${PLESK_HOST}"
