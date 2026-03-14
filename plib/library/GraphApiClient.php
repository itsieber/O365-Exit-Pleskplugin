<?php

/**
 * Microsoft Graph API Client – listet O365-Postfächer auf.
 * Benötigte App-Berechtigung: User.Read.All (Application)
 */
class Modules_O365ExitMigrator_GraphApiClient
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private $tokenManager;

    public function __construct(Modules_O365ExitMigrator_O365TokenManager $tokenManager)
    {
        $this->tokenManager = $tokenManager;
    }

    /**
     * Gibt alle Benutzer/Postfächer des Tenants zurück.
     * @return array [ ['email' => '...', 'display_name' => '...'], ... ]
     */
    public function listMailboxes(): array
    {
        // Graph API benötigt separaten Token-Scope
        // Für User.Read.All brauchen wir https://graph.microsoft.com/.default
        $token = $this->_fetchGraphToken();

        $users    = [];
        $url      = self::GRAPH_BASE . '/users?$select=mail,displayName,userPrincipalName&$top=999';

        while ($url) {
            $response = $this->_get($url, $token);

            foreach ($response['value'] ?? [] as $user) {
                $email = $user['mail'] ?? $user['userPrincipalName'] ?? '';
                if (empty($email) || strpos($email, '@') === false) {
                    continue;
                }
                $users[] = [
                    'email'        => $email,
                    'display_name' => $user['displayName'] ?? $email,
                ];
            }

            $url = $response['@odata.nextLink'] ?? null;
        }

        usort($users, fn($a, $b) => strcmp($a['email'], $b['email']));
        return $users;
    }

    private function _fetchGraphToken(): string
    {
        // Neuer Token mit graph.microsoft.com scope
        $domainId     = $this->tokenManager->getDomainId();
        $tenantId     = pm_Settings::get('domain_tenant_' . $domainId, '');
        $clientId     = pm_Settings::get('domain_client_' . $domainId, '');
        $clientSecret = pm_Settings::get('domain_secret_' . $domainId, '');

        $url = 'https://login.microsoftonline.com/' . urlencode($tenantId) . '/oauth2/v2.0/token';

        $postData = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'scope'         => 'https://graph.microsoft.com/.default',
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            $msg = $data['error_description'] ?? 'HTTP ' . $httpCode;
            throw new Exception('Graph Token Fehler: ' . $msg);
        }

        return $data['access_token'];
    }

    private function _get(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Graph API Fehler HTTP ' . $httpCode . ': ' . $response);
        }

        return json_decode($response, true) ?? [];
    }
}
