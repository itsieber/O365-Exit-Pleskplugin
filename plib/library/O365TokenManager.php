<?php

/**
 * Holt OAuth2 Access Tokens von Azure AD via Client Credentials Flow.
 *
 * Voraussetzung Azure AD:
 *  1. App-Registrierung anlegen (Multi-Tenant)
 *  2. API-Berechtigungen: Microsoft Graph → User.Read.All + Mail.Read (Application)
 *  3. Admin-Consent erteilen
 *
 * Scope: https://graph.microsoft.com/.default
 */
class Modules_O365ExitMigrator_O365TokenManager
{
    private $domainId;
    private $tenantId;
    private $clientId;
    private $clientSecret;

    // Token-Cache (pro Request)
    private static $cache = [];

    public function __construct($domainId)
    {
        $this->domainId     = $domainId;
        $this->tenantId     = pm_Settings::get('domain_tenant_' . $domainId, '');
        $this->clientId     = pm_Settings::get('global_client_id', '');
        $this->clientSecret = pm_Settings::get('global_client_secret', '');

        if (empty($this->tenantId) || empty($this->clientId) || empty($this->clientSecret)) {
            throw new Exception('O365 nicht verbunden. Bitte "Mit Microsoft verbinden" klicken.');
        }
    }

    /**
     * Gibt einen gültigen Access Token zurück (aus Cache oder neu geholt).
     */
    public function getDomainId(): string
    {
        return $this->domainId;
    }

    public function getAccessToken(): string
    {
        $cacheKey = $this->domainId;

        if (isset(self::$cache[$cacheKey])) {
            $cached = self::$cache[$cacheKey];
            // Token noch mind. 5 Minuten gültig?
            if ($cached['expires_at'] > time() + 300) {
                return $cached['token'];
            }
        }

        $token = $this->fetchNewToken();
        self::$cache[$cacheKey] = $token;
        return $token['token'];
    }

    /**
     * Holt einen neuen Token von Azure AD.
     */
    private function fetchNewToken(): array
    {
        $url = 'https://login.microsoftonline.com/' . urlencode($this->tenantId) . '/oauth2/v2.0/token';

        $postData = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
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
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception('cURL-Fehler: ' . $curlError);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || empty($data['access_token'])) {
            $msg = $data['error_description'] ?? $data['error'] ?? 'Unbekannter Fehler (HTTP ' . $httpCode . ')';
            throw new Exception('Azure AD Token-Fehler: ' . $msg);
        }

        return [
            'token'      => $data['access_token'],
            'expires_at' => time() + (int)($data['expires_in'] ?? 3600),
        ];
    }
}
