<?php

/**
 * Startet imapsync als Background-Prozess (nohup).
 */
class Modules_O365ExitMigrator_ImapsyncRunner
{
    private $lastPid = 0;

    public function start(
        int    $jobId,
        string $o365Email,
        string $accessToken,
        string $pleskEmail,
        string $pleskPass,
        string $dateFrom
    ) {
        $imapsync  = pm_Settings::get('imapsync_path', '/usr/bin/imapsync');
        $imapHost  = pm_Settings::get('imap_host', '127.0.0.1');
        $imapPort  = pm_Settings::get('imap_port', '993');
        $logFile   = pm_Context::getVarDir() . 'job_' . $jobId . '.log';
        $tokenFile = pm_Context::getVarDir() . 'job_' . $jobId . '.token';
        $script    = dirname(__DIR__) . '/sbin/run_migration.sh';

        // Token in temporäre Datei schreiben (wird im Script gelesen und danach gelöscht)
        file_put_contents($tokenFile, $accessToken);
        chmod($tokenFile, 0600);

        $searchArg = '';
        if (!empty($dateFrom)) {
            $searchArg = '--search ' . escapeshellarg('SINCE ' . date('d-M-Y', strtotime($dateFrom)));
        }

        $cmd = implode(' ', [
            'nohup',
            escapeshellarg($script),
            escapeshellarg($imapsync),
            escapeshellarg($o365Email),
            escapeshellarg($tokenFile),
            escapeshellarg($imapHost),
            escapeshellarg($imapPort),
            escapeshellarg($pleskEmail),
            escapeshellarg($pleskPass),
            escapeshellarg($logFile),
            $searchArg,
            '> /dev/null 2>&1 &',
            'echo $!',
        ]);

        $pid = (int)shell_exec($cmd);
        $this->lastPid = $pid;
    }

    public function getLastPid(): int
    {
        return $this->lastPid;
    }
}
