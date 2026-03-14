<?php

/**
 * Startet die Graph-API-basierte Migration als Background-Prozess.
 * Ersetzt ImapsyncRunner (kein imapsync/IMAP mehr nötig).
 */
class Modules_O365ExitMigrator_GraphMigrationRunner
{
    private $lastPid = 0;

    public function start(
        int    $jobId,
        int    $domainId,
        string $o365Email,
        string $pleskEmail,
        string $dateFrom
    ) {
        $tenantId     = pm_Settings::get('domain_tenant_' . $domainId, '');
        $clientId     = pm_Settings::get('global_client_id', '');
        $clientSecret = pm_Settings::get('global_client_secret', '');

        if (empty($tenantId) || empty($clientId) || empty($clientSecret)) {
            throw new Exception('O365 nicht verbunden oder Einstellungen fehlen.');
        }

        $logFile   = pm_Context::getVarDir() . 'job_' . $jobId . '.log';
        $credsFile = pm_Context::getVarDir() . 'job_' . $jobId . '.creds';
        $script    = dirname(__DIR__) . '/sbin/migrate_via_graph.php';

        // Credentials in temporäre Datei (600) schreiben – Script löscht sie sofort
        file_put_contents($credsFile, json_encode([
            'tenant_id'     => $tenantId,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]));
        chmod($credsFile, 0600);

        $args = implode(' ', [
            escapeshellarg($credsFile),
            escapeshellarg($o365Email),
            escapeshellarg($pleskEmail),
            escapeshellarg($logFile),
            !empty($dateFrom) ? escapeshellarg($dateFrom) : '',
        ]);

        $cmd = 'nohup php ' . escapeshellarg($script) . ' ' . $args
             . ' > /dev/null 2>&1 & echo $!';

        $pid = (int)shell_exec($cmd);
        $this->lastPid = $pid;
    }

    public function getLastPid(): int
    {
        return $this->lastPid;
    }
}
