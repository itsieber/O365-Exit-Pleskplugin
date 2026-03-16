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

// UTF-8 Locale setzen damit escapeshellarg() Umlaute nicht strippt
setlocale(LC_ALL, 'en_US.UTF-8');
putenv('LANG=en_US.UTF-8');
putenv('LC_ALL=en_US.UTF-8');

if ($argc < 6) {
    fwrite(STDERR, "Usage: migrate_via_graph.php <creds_file> <o365_email> <plesk_email> <log_file> <db_file> [date_from] [fullsync]\n");
    exit(1);
}

$credsFile  = $argv[1];
$o365Email  = $argv[2];
$pleskEmail = $argv[3];
$logFile    = $argv[4];
$dbFile     = $argv[5];
$dateFrom   = ($argv[6] ?? '') === "''" ? '' : ($argv[6] ?? '');
$fullsync   = ($argv[7] ?? '0') === '1';

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

// ── SQLite Ordner-Tracking ────────────────────────────────────────────────────

function dbConnect(): PDO {
    global $dbFile;
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS synced_folders (
        o365_email    TEXT NOT NULL,
        folder_id     TEXT NOT NULL,
        folder_name   TEXT NOT NULL,
        synced_at     TEXT NOT NULL,
        message_count INTEGER DEFAULT 0,
        PRIMARY KEY (o365_email, folder_id)
    )");
    return $pdo;
}

function isFolderSynced(string $o365Email, string $folderId, string $folderName): bool {
    global $fullsync;
    if ($fullsync) return false;
    $pdo  = dbConnect();
    $stmt = $pdo->prepare("SELECT synced_at FROM synced_folders WHERE o365_email=? AND (folder_id=? OR folder_name=?)");
    $stmt->execute([$o365Email, $folderId, $folderName]);
    return $stmt->fetch() !== false;
}

function markFolderSynced(string $o365Email, string $folderId, string $folderName, int $count): void {
    $pdo  = dbConnect();
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO synced_folders (o365_email, folder_id, folder_name, synced_at, message_count) VALUES (?,?,?,?,?)");
    $stmt->execute([$o365Email, $folderId, $folderName, date('Y-m-d H:i:s'), $count]);
}

// ── Graph API Helpers ─────────────────────────────────────────────────────────

$graphToken = '';
$graphTokenExpiry = 0;

function httpPost(string $url, string $body, array $headers): array {
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $body,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $response = file_get_contents($url, false, $ctx);
    $code     = 0;
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $code = (int)$m[1];
    }
    return ['code' => $code, 'body' => (string)$response];
}

function httpGet(string $url, array $headers): array {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => implode("\r\n", $headers),
        'timeout'       => 60,
        'ignore_errors' => true,
    ]]);
    $response = file_get_contents($url, false, $ctx);
    $code     = 0;
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $code = (int)$m[1];
    }
    return ['code' => $code, 'body' => (string)$response];
}

function getGraphToken(): string {
    global $tenantId, $clientId, $clientSecret, $graphToken, $graphTokenExpiry;
    if (!empty($graphToken) && time() < $graphTokenExpiry - 60) {
        return $graphToken;
    }
    $url  = 'https://login.microsoftonline.com/' . urlencode($tenantId) . '/oauth2/v2.0/token';
    $body = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'scope'         => 'https://graph.microsoft.com/.default',
    ]);
    $res  = httpPost($url, $body, ['Content-Type: application/x-www-form-urlencoded']);
    $data = json_decode($res['body'], true);
    if ($res['code'] !== 200 || empty($data['access_token'])) {
        throw new RuntimeException('Graph Token Fehler: ' . ($data['error_description'] ?? 'HTTP ' . $res['code']));
    }
    $graphToken       = $data['access_token'];
    $graphTokenExpiry = time() + (int)($data['expires_in'] ?? 3600);
    return $graphToken;
}

function graphGet(string $url): array {
    foreach ([0, 5, 15, 30] as $wait) {
        if ($wait > 0) sleep($wait);
        $token = getGraphToken();
        $res   = httpGet($url, ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        if ($res['code'] === 200) {
            return json_decode($res['body'], true) ?? [];
        }
        if (!in_array($res['code'], [429, 500, 503, 504])) break;
    }
    throw new RuntimeException('Graph GET HTTP ' . $res['code'] . ': ' . substr($res['body'], 0, 200));
}

function graphGetRaw(string $url): string {
    foreach ([0, 5, 15, 30] as $wait) {
        if ($wait > 0) sleep($wait);
        $token = getGraphToken();
        $res   = httpGet($url, ['Authorization: Bearer ' . $token, 'Accept: text/plain']);
        if ($res['code'] === 200) return $res['body'];
        if (!in_array($res['code'], [429, 500, 503, 504])) break;
    }
    throw new RuntimeException('Graph raw GET HTTP ' . $res['code']);
}


// ── Ordner-Mapping O365 → Dovecot ─────────────────────────────────────────────

function mapFolderName(string $displayName): string {
    $map = [
        // Englisch
        'inbox'                => 'INBOX',
        'sent items'           => 'INBOX.Sent',
        'sent'                 => 'INBOX.Sent',
        'drafts'               => 'INBOX.Drafts',
        'deleted items'        => 'INBOX.Trash',
        'junk email'           => 'INBOX.Spam',
        'junk'                 => 'INBOX.Spam',
        'spam'                 => 'INBOX.Spam',
        'archive'              => 'INBOX.Archive',
        'outbox'               => 'INBOX.Drafts',
        // Deutsch
        'posteingang'          => 'INBOX',
        'gesendete elemente'   => 'INBOX.Sent',
        'gesendet'             => 'INBOX.Sent',
        'entwürfe'             => 'INBOX.Drafts',
        'entwurfe'             => 'INBOX.Drafts',
        'gelöschte elemente'   => 'INBOX.Trash',
        'geloschte elemente'   => 'INBOX.Trash',
        'junk-e-mail'          => 'INBOX.Spam',
        'junk-email'           => 'INBOX.Spam',
        'archiv'               => 'INBOX.Archive',
        'postausgang'          => 'INBOX.Drafts',
    ];
    $lower = mb_strtolower($displayName, 'UTF-8');
    // Unbekannte Ordner ebenfalls unter INBOX. einhängen
    return $map[$lower] ?? 'INBOX.' . $displayName;
}

// ── Duplikate prüfen via doveadm ──────────────────────────────────────────────

function messageExistsInDovecot(string $pleskEmail, string $mailbox, string $messageId): bool {
    if (empty($messageId)) {
        return false;
    }
    // doveadm search gibt UIDs aus; leere Ausgabe = nicht gefunden
    $cmd = 'sudo doveadm search -u ' . escapeshellarg($pleskEmail)
         . ' mailbox ' . escapeshellarg($mailbox)
         . ' header Message-ID ' . escapeshellarg($messageId)
         . ' 2>/dev/null';
    $output = trim((string)shell_exec($cmd));
    return !empty($output);
}

// ── Nachricht in Dovecot speichern ────────────────────────────────────────────

function ensureMailbox(string $pleskEmail, string $mailbox): void {
    $cmd = 'LANG=en_US.UTF-8 sudo doveadm mailbox create -u '
         . escapeshellarg($pleskEmail) . ' '
         . escapeshellarg($mailbox)
         . ' 2>&1';
    shell_exec($cmd); // ignoriert Fehler wenn bereits vorhanden
}

function saveToDovecot(string $pleskEmail, string $mailbox, string $rawMime): bool {
    $tmpFile = tempnam(sys_get_temp_dir(), 'o365mig_');
    file_put_contents($tmpFile, $rawMime);

    $cmd = 'LANG=en_US.UTF-8 sudo doveadm save -u ' . escapeshellarg($pleskEmail)
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

// ── Ordner rekursiv laden ─────────────────────────────────────────────────────

function loadFoldersRecursive(string $email, ?string $parentId, string $pathPrefix): array
{
    $result = [];

    if ($parentId === null) {
        $url = 'https://graph.microsoft.com/v1.0/users/' . urlencode($email)
             . '/mailFolders?$top=50&$select=id,displayName,totalItemCount,childFolderCount';
    } else {
        $url = 'https://graph.microsoft.com/v1.0/users/' . urlencode($email)
             . '/mailFolders/' . urlencode($parentId)
             . '/childFolders?$top=50&$select=id,displayName,totalItemCount,childFolderCount';
    }

    while ($url) {
        $data = graphGet($url);
        foreach ($data['value'] ?? [] as $folder) {
            $folder['_path_prefix'] = $pathPrefix;
            $result[] = $folder;

            // Unterordner rekursiv laden
            if ((int)($folder['childFolderCount'] ?? 0) > 0) {
                $subPath = $pathPrefix ? $pathPrefix . '.' . $folder['displayName'] : $folder['displayName'];
                $children = loadFoldersRecursive($email, $folder['id'], $subPath);
                $result = array_merge($result, $children);
            }
        }
        $url = $data['@odata.nextLink'] ?? null;
    }

    return $result;
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
    // Ordner rekursiv laden (inkl. Unterordner)
    $folders = loadFoldersRecursive($o365Email, null, '');

    logMsg('Gefundene Ordner: ' . count($folders));

    $totalMigrated = 0;
    $totalSkipped  = 0;
    $totalErrors   = 0;

    foreach ($folders as $folder) {
        $folderId      = $folder['id'];
        $folderDisplay = $folder['displayName'];
        $pathPrefix    = $folder['_path_prefix'] ?? '';

        // Unterordner: Pfad aus Parent + eigenem Namen aufbauen
        if (!empty($pathPrefix)) {
            $targetFolder = 'INBOX.' . $pathPrefix . '.' . $folderDisplay;
        } else {
            $targetFolder = mapFolderName($folderDisplay);
        }
        $totalItems    = (int)($folder['totalItemCount'] ?? 0);

        if ($totalItems === 0) {
            logMsg("Ordner übersprungen (leer): $folderDisplay");
            continue;
        }

        if (isFolderSynced($o365Email, $folderId, $folderDisplay)) {
            logMsg("Ordner übersprungen (bereits synchronisiert): $folderDisplay");
            continue;
        }

        logMsg("Ordner: $folderDisplay → $targetFolder ($totalItems Nachrichten)");
        ensureMailbox($pleskEmail, $targetFolder);

        // Nachrichten mit Pagination laden
        $filter = '';
        if (!empty($dateFrom)) {
            $isoDate = date('Y-m-d', strtotime($dateFrom)) . 'T00:00:00Z';
            $filter  = '&$filter=' . str_replace(' ', '%20', 'receivedDateTime ge ' . $isoDate);
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
        if ($folderErrors === 0) {
            markFolderSynced($o365Email, $folderId, $folderDisplay, $folderMigrated + $folderSkipped);
        }
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
