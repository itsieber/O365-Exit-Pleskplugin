#!/bin/bash
cd "$(dirname "$0")"

ZIP_NAME="o365-exit-migrator.zip"
rm -f "$ZIP_NAME"

zip -r "$ZIP_NAME" \
    meta.xml \
    version.json \
    _meta/ \
    htdocs/ \
    plib/ \
    -x "*.DS_Store"

echo ""
echo "✓ ${ZIP_NAME} erstellt ($(du -h "$ZIP_NAME" | cut -f1))"
echo ""
echo "Installation auf Plesk:"
echo "  scp ${ZIP_NAME} root@webserver.itsieber.ch:/tmp/"
echo "  ssh root@webserver.itsieber.ch 'plesk bin extension -i /tmp/${ZIP_NAME}'"
