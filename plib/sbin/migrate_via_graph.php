#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * migrate_via_graph.php – Migriert O365-E-Mails via Graph API nach Dovecot.
 *
 * Aufruf:
 *   php migrate_via_graph.php <creds_file> <o365_email> <plesk_email> <log_file> [date_from]
 *
 * creds_file enthält JSON: { "tenant_id": "...", "client_id": "...", "client_secret": "..." }
 * Die Datei wird sofort nach dem Lesen gelöscht.
 *
 * Benötigte Azure App-Permissions (Application):
 *   - User.Read.All
 *   - Mail.Read
 */

if ($argc < 5) {
    fwrite(STDERR, "Usage: migrate_via_graph.php <creds_file> <o365_email> <plesk_email> <log_file> [date_from]\n");
    exit(1);
}

$credsFile  = $argv[1];
$o365Email  = $argv[2];
$pleskEmail = $argv[3];
$logFile    = $argv[4];
$dateFrom   = $argv[5] ?? '';

// ── Credentials laden und sofort löschen ──────────────────────────────────────

if (!file_exists($credsFile)) {
    fwrite(STDERR, "Credentials file not found: $credsFile\n");
    exit(1);
}
$creds = json_decode(file_get_contents($credsFile), true);
unlink($credsFile);

$tenantId     = $creds['tenant_id']     ?? '';
$clientId     = $creds['client_id']     ?? '';
$clientSecret = $creds['client_secret'] ?? '';

if (empty($tenantId) || empty($clientId) || empty($clientSecret)) {
    logMsg('FEHLER: Unvollständige Credentials.');
    exit(1);
}

// ── Logging ───────────────────────────────────────────────────────────────────

function logMsg(string $msg): void {
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// ── Graph API Helpers ─────────────────────────────────────────────────────────

$graphToken = '';
$graphTokenExpiry = 0;

function getGraphToken(): string {
    global $tenantId, $clientId, $clientSecret, $graphToken, $graphTokenExpiry;
    if (!empty($graphToken) && time() < $graphTokenExpiry - 60) {
        return $graphToken;
    }
    $url = 'https://login.microsoftonline.com/' . urlencode($tenantId) . '/oauth2/v2.0/token';
    $postData = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'scope'         => 'https://graph.microsoft.com/.default',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($response, true);
    if ($httpCode !== 200 || empty($data['access_token'])) {
        throw new RuntimeException('Graph Token Fehler: ' . ($data['error_description'] ?? 'HTTP ' . $httpCode));
    }
    $graphToken       = $data['access_token'];
    $graphTokenExpiry = time() + (int)($data['expires_in'] ?? 3600);
    return $graphToken;
}

function graphGet(string $url): array {
    $token = getGraphToken();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        throw new RuntimeException('Graph GET HTTP ' . $httpCode . ': ' . substr($response, 0, 200));
    }
    return json_decode($response, true) ?? [];
}

function graphGetRaw(string $url): string {
    $token = getGraphToken();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: text/plain',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        throw new RuntimeException('Graph raw GET HTTP ' . $httpCode);
    }
    return (string)$response;
}

// ── Ordner-Mapping O365 → Dovecot ─────────────────────────────────────────────

function mapFolderName(string $displayName): string {
    $map = [
        'inbox'         => 'INBOX',
        'sent items'    => 'Sent',
        'sent'          => 'Sent',
        'drafts'        => 'Drafts',
        'deleted items' => 'Trash',
        'junk email'    => 'Junk',
        'junk'          => 'Junk',
        'spam'          => 'Junk',
        'archive'       => 'Archive',
    ];
    $lower = strtolower($displayName);
    return $map[$lower] ?? $displayName;
}

// ── Duplikate prüfen via doveadm ──────────────────────────────────────────────

function messageExistsInDovecot(string $pleskEmail, string $mailbox, string $messageId): bool {
    if (empty($messageId)) {
        return false;
    }
    // doveadm search gibt UIDs aus; leere Ausgabe = nicht gefunden
    $cmd = 'doveadm search -u ' . escapeshellarg($pleskEmail)
         . ' mailbox ' . escapeshellarg($mailbox)
         . ' header Message-ID ' . escapeshellarg($messageId)
         . ' 2>/dev/null';
    $output = trim((string)shell_exec($cmd));
    return !empty($output);
}

// ── Nachricht in Dovecot speichern ────────────────────────────────────────────

function saveToDovecot(string $pleskEmail, string $mailbox, string $rawMime): bool {
    $tmpFile = tempnam(sys_get_temp_dir(), 'o365mig_');
    file_put_contents($tmpFile, $rawMime);

    $cmd = 'doveadm save -u ' . escapeshellarg($pleskEmail)
         . ' -m ' . escapeshellarg($mailbox)
         . ' < ' . escapeshellarg($tmpFile)
         . ' 2>&1';
    $output = shell_exec($cmd);
    unlink($tmpFile);

    if (!empty($output)) {
        logMsg('  doveadm warn: ' . trim($output));
    }
    return true;
}

// ── Hauptprogramm ─────────────────────────────────────────────────────────────

logMsg('=== O365 Exit Migration gestartet ===');
logMsg('Von:  ' . $o365Email);
logMsg('Nach: ' . $pleskEmail);
if (!empty($dateFrom)) {
    logMsg('Ab:   ' . $dateFrom);
}
logMsg('');

try {
    // Ordner des O365-Postfachs holen
    $foldersUrl = 'https://graph.microsoft.com/v1.0/users/' . urlencode($o365Email)
                . '/mailFolders?$top=50&$select=id,displayName,totalItemCount';
    $foldersData = graphGet($foldersUrl);
    $folders = $foldersData['value'] ?? [];

    logMsg('Gefundene Ordner: ' . count($folders));

    $totalMigrated = 0;
    $totalSkipped  = 0;
    $totalErrors   = 0;

    foreach ($folders as $folder) {
        $folderId      = $folder['id'];
        $folderDisplay = $folder['displayName'];
        $targetFolder  = mapFolderName($folderDisplay);
        $totalItems    = (int)($folder['totalItemCount'] ?? 0);

        if ($totalItems === 0) {
            continue;
        }

        logMsg("Ordner: $folderDisplay → $targetFolder ($totalItems Nachrichten)");

        // Nachrichten mit Pagination laden
        $filter = '';
        if (!empty($dateFrom)) {
            $isoDate = date('Y-m-d', strtotime($dateFrom)) . 'T00:00:00Z';
            $filter  = '&$filter=receivedDateTime ge ' . urlencode($isoDate);
        }

        $messagesUrl = 'https://graph.microsoft.com/v1.0/users/' . urlencode($o365Email)
                     . '/mailFolders/' . urlencode($folderId)
                     . '/messages?$top=50&$select=id,internetMessageId,subject,receivedDateTime'
                     . $filter;

        $folderMigrated = 0;
        $folderSkipped  = 0;
        $folderErrors   = 0;

        while ($messagesUrl) {
            $msgData  = graphGet($messagesUrl);
            $messages = $msgData['value'] ?? [];

            foreach ($messages as $msg) {
                $msgId     = $msg['id'];
                $messageId = trim($msg['internetMessageId'] ?? '');
                $subject   = $msg['subject'] ?? '(kein Betreff)';

                // Duplikat-Prüfung
                if (messageExistsInDovecot($pleskEmail, $targetFolder, $messageId)) {
                    $folderSkipped++;
                    continue;
                }

                // Rohe MIME-Nachricht herunterladen
                try {
                    $rawUrl = 'https://graph.microsoft.com/v1.0/users/' . urlencode($o365Email)
                            . '/messages/' . urlencode($msgId) . '/$value';
                    $rawMime = graphGetRaw($rawUrl);

                    if (empty($rawMime)) {
                        throw new RuntimeException('Leere MIME-Antwort');
                    }

                    saveToDovecot($pleskEmail, $targetFolder, $rawMime);
                    $folderMigrated++;
                } catch (RuntimeException $e) {
                    logMsg("  FEHLER bei \"$subject\": " . $e->getMessage());
                    $folderErrors++;
                }
            }

            $messagesUrl = $msgData['@odata.nextLink'] ?? null;
        }

        logMsg("  → Migriert: $folderMigrated | Übersprungen: $folderSkipped | Fehler: $folderErrors");
        $totalMigrated += $folderMigrated;
        $totalSkipped  += $folderSkipped;
        $totalErrors   += $folderErrors;
    }

    logMsg('');
    logMsg("=== Fertig ===");
    logMsg("Gesamt migriert:     $totalMigrated");
    logMsg("Gesamt übersprungen: $totalSkipped");
    logMsg("Gesamt Fehler:       $totalErrors");
    logMsg('=== Ended on ' . date('Y-m-d H:i:s') . ' ===');

} catch (Exception $e) {
    logMsg('KRITISCHER FEHLER: ' . $e->getMessage());
    exit(1);
}
