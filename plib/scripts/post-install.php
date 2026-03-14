<?php

pm_Context::init('o365-exit-migrator');

// var/-Verzeichnis anlegen (für SQLite DB + Job-Logs)
$varDir = pm_Context::getVarDir();
if (!is_dir($varDir)) {
    mkdir($varDir, 0750, true);
}

// SQLite Datenbank initialisieren
$dbPath = $varDir . 'jobs.sqlite';
$db = new SQLite3($dbPath);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('
    CREATE TABLE IF NOT EXISTS jobs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        domain_id   TEXT NOT NULL,
        o365_email  TEXT NOT NULL,
        plesk_email TEXT NOT NULL,
        date_from   TEXT,
        status      TEXT NOT NULL DEFAULT "running",
        pid         INTEGER,
        started_at  TEXT NOT NULL,
        finished_at TEXT
    )
');
$db->close();
