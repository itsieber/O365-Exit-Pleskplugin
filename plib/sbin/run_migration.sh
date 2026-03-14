#!/bin/bash
# run_migration.sh – Startet imapsync mit OAuth2 Token für O365
#
# Aufruf:
#   run_migration.sh <imapsync> <o365_email> <token_file> <imap_host> <imap_port> <plesk_email> <plesk_pass> <log_file> [search_args...]
#
set -euo pipefail

IMAPSYNC="$1"
O365_EMAIL="$2"
TOKEN_FILE="$3"
IMAP_HOST="$4"
IMAP_PORT="$5"
PLESK_EMAIL="$6"
PLESK_PASS="$7"
LOG_FILE="$8"
shift 8
EXTRA_ARGS="${*:-}"

# Token lesen und Datei sofort löschen
ACCESS_TOKEN=$(cat "$TOKEN_FILE")
rm -f "$TOKEN_FILE"

echo "=== O365 Exit Migration ===" >> "$LOG_FILE"
echo "Von:   $O365_EMAIL"          >> "$LOG_FILE"
echo "Nach:  $PLESK_EMAIL"         >> "$LOG_FILE"
echo "Start: $(date)"              >> "$LOG_FILE"
echo ""                            >> "$LOG_FILE"

"$IMAPSYNC" \
    --host1 outlook.office365.com \
    --port1 993 \
    --ssl1 \
    --user1 "$O365_EMAIL" \
    --authmech1 XOAUTH2 \
    --oauthaccesstoken1 "$ACCESS_TOKEN" \
    --host2 "$IMAP_HOST" \
    --port2 "$IMAP_PORT" \
    --ssl2 \
    --user2 "$PLESK_EMAIL" \
    --password2 "$PLESK_PASS" \
    --automap \
    --useheader 'Message-Id' \
    --nofoldersizes \
    --exclude '^Junk$' \
    --exclude '^Deleted Items$' \
    $EXTRA_ARGS \
    >> "$LOG_FILE" 2>&1

echo "" >> "$LOG_FILE"
echo "=== Ended on $(date) ===" >> "$LOG_FILE"
