<?php

class Modules_O365ExitMigrator_Updater
{
    private const GITHUB_REPO = 'itsieber/O365-Exit-Pleskplugin'; // github.com/itsieber/O365-Exit-Pleskplugin
    private const GITHUB_API  = 'https://api.github.com/repos/';

    public function checkForUpdate(): array
    {
        $current = $this->getCurrentVersion();
        $latest  = $this->_getLatestRelease();

        if ($latest === null) {
            return ['available' => false, 'error' => 'GitHub API nicht erreichbar'];
        }

        $latestVersion = ltrim($latest['tag_name'], 'V');
        $isNewer = version_compare($latestVersion, ltrim($current, 'V'), '>');

        return [
            'available'    => $isNewer,
            'current'      => $current,
            'latest'       => $latest['tag_name'],
            'download_url' => $this->_getZipUrl($latest),
            'changelog'    => $latest['body'] ?? '',
            'date'         => $latest['published_at'] ?? '',
        ];
    }

    public function installUpdate(): array
    {
        $latest = $this->_getLatestRelease();
        if ($latest === null) {
            return ['success' => false, 'message' => 'GitHub API nicht erreichbar'];
        }

        $zipUrl = $this->_getZipUrl($latest);
        if (empty($zipUrl)) {
            return ['success' => false, 'message' => 'Kein ZIP-Asset im Release gefunden'];
        }

        $tmpFile = tempnam('/tmp', 'o365migrator-') . '.zip';

        $ch = curl_init($zipUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: o365-exit-migrator-updater']);
        $zipData  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($zipData)) {
            return ['success' => false, 'message' => "Download fehlgeschlagen (HTTP {$httpCode})"];
        }

        file_put_contents($tmpFile, $zipData);

        $output   = [];
        $exitCode = 0;
        exec('plesk bin extension -i ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
        unlink($tmpFile);

        if ($exitCode === 0) {
            return [
                'success' => true,
                'message' => 'Update auf ' . $latest['tag_name'] . ' erfolgreich installiert',
            ];
        }

        return ['success' => false, 'message' => 'Installation fehlgeschlagen: ' . implode("\n", $output)];
    }

    public function getCurrentVersion(): string
    {
        $path = pm_Context::getHtdocsDir() . '/version.json';
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (isset($data['version'])) {
                return $data['version'];
            }
        }
        return 'unbekannt';
    }

    private function _getLatestRelease(): ?array
    {
        $url = self::GITHUB_API . self::GITHUB_REPO . '/releases/latest';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: o365-exit-migrator-updater',
            'Accept: application/vnd.github.v3+json',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200 ? json_decode($response, true) : null;
    }

    private function _getZipUrl(array $release): string
    {
        foreach ($release['assets'] ?? [] as $asset) {
            if (str_ends_with($asset['name'], '.zip')) {
                return $asset['browser_download_url'];
            }
        }
        return '';
    }
}
