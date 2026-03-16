<?php

/**
 * SQLite-basierte Job-Verwaltung für Migrationsjobs.
 */
class Modules_O365ExitMigrator_JobRepository
{
    private $dbPath;
    private $pdo;

    public function __construct()
    {
        $this->dbPath = pm_Context::getVarDir() . 'jobs.sqlite';
        $this->_connect();
    }

    private function _connect()
    {
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_id   TEXT NOT NULL,
                o365_email  TEXT NOT NULL,
                plesk_email TEXT NOT NULL,
                date_from   TEXT,
                status      TEXT NOT NULL DEFAULT 'running',
                pid         INTEGER,
                started_at  TEXT NOT NULL,
                finished_at TEXT
            )
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS synced_folders (
                o365_email     TEXT NOT NULL,
                folder_id      TEXT NOT NULL,
                folder_name    TEXT NOT NULL,
                synced_at      TEXT NOT NULL,
                message_count  INTEGER DEFAULT 0,
                PRIMARY KEY (o365_email, folder_id)
            )
        ");
    }

    public function create(string $domainId, string $o365Email, string $pleskEmail, string $dateFrom, bool $fullsync = false): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO jobs (domain_id, o365_email, plesk_email, date_from, started_at)
            VALUES (:domain_id, :o365_email, :plesk_email, :date_from, :started_at)
        ");
        $stmt->execute([
            ':domain_id'   => $domainId,
            ':o365_email'  => $o365Email,
            ':plesk_email' => $pleskEmail,
            ':date_from'   => $dateFrom,
            ':started_at'  => date('Y-m-d H:i:s'),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getSyncedFolders(string $o365Email): array
    {
        $stmt = $this->pdo->prepare("SELECT folder_id, folder_name, synced_at, message_count FROM synced_folders WHERE o365_email = :email");
        $stmt->execute([':email' => $o365Email]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['folder_id']] = $row;
        }
        return $result;
    }

    public function setPid(int $jobId, int $pid)
    {
        $this->pdo->prepare("UPDATE jobs SET pid = :pid WHERE id = :id")
            ->execute([':pid' => $pid, ':id' => $jobId]);
    }

    public function finish(int $jobId, string $status)
    {
        $this->pdo->prepare("UPDATE jobs SET status = :status, finished_at = :finished WHERE id = :id")
            ->execute([
                ':status'   => $status,
                ':finished' => date('Y-m-d H:i:s'),
                ':id'       => $jobId,
            ]);
    }

    public function getAll(): array
    {
        return $this->pdo
            ->query("SELECT * FROM jobs ORDER BY id DESC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM jobs WHERE id = :id");
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
