<?php

class IndexController extends pm_Controller_Action
{
    public function init()
    {
        parent::init();
        $this->view->pageTitle = 'O365 Exit - Mail Migrator';
    }

    public function indexAction()
    {
        $this->_forward('domains');
    }

    // ─── Sicherheit: Domain-Zugriff prüfen ───────────────────────────────────

    private function _getAccessibleDomains()
    {
        $domId = $this->getRequest()->getParam('dom_id', '');

        if (!empty($domId)) {
            return [pm_Domain::getByDomainId($domId)];
        }

        return pm_Domain::getAllDomains();
    }

    // ─── Tab: Domains ────────────────────────────────────────────────────────

    public function domainsAction()
    {
        $this->view->tabs = $this->_getTabs('domains');

        $domains = $this->_getAccessibleDomains();

        $rows = [];
        foreach ($domains as $domain) {
            $id        = $domain->getId();
            $name      = $domain->getName();
            $tenantId  = pm_Settings::get('domain_tenant_' . $id, '');
            $connected = !empty($tenantId);

            $connectUrl   = pm_Context::getActionUrl('index', 'connect')    . '?domain_id=' . $id;
            $mailboxesUrl = pm_Context::getActionUrl('index', 'mailboxes')  . '?domain_id=' . $id;

            if ($connected) {
                $status  = '<span style="color:green">&#10003; verbunden</span>';
                $actions = '<a href="' . $mailboxesUrl . '" class="btn">Postfächer</a> '
                         . '<a href="' . $connectUrl . '" class="btn">Einstellungen</a>';
            } else {
                $status  = '<span style="color:#aaa">&#8212; nicht verbunden</span>';
                $actions = '<a href="' . $connectUrl . '" class="btn btn-primary">O365 verknüpfen</a>';
            }

            $rows[] = [
                'id'      => $id,
                'name'    => $name,
                'status'  => $status,
                'actions' => $actions,
            ];
        }

        $list = new pm_View_List_Simple($this->view, $this->_request);
        $list->setData($rows);
        $list->setColumns([
            'name'    => ['title' => 'Domain',   'noEscape' => false],
            'status'  => ['title' => 'O365',     'noEscape' => true],
            'actions' => ['title' => 'Aktionen', 'noEscape' => true],
        ]);
        $this->view->list = $list;
    }

    // ─── Tab: O365 verknüpfen (pro Domain) ───────────────────────────────────

    public function connectAction()
    {
        $this->view->tabs = $this->_getTabs('domains');

        $domainId = $this->getRequest()->getParam('domain_id');
        $domain   = pm_Domain::getByDomainId($domainId);

        $this->view->domain        = $domain;
        $this->view->domainId      = $domainId;
        $this->view->tenantId      = pm_Settings::get('domain_tenant_' . $domainId, '');
        $this->view->callbackUrl   = $this->_getCallbackUrl();
        $this->view->oauthStartUrl = pm_Context::getActionUrl('index', 'oauth-start') . '?domain_id=' . $domainId;
        $this->view->globalReady   = !empty(pm_Settings::get('global_client_id', ''));
    }

    // ─── OAuth Start: Redirect zu Microsoft ──────────────────────────────────

    // ─── Admin Consent Start: Redirect zu Microsoft ──────────────────────────

    public function oauthStartAction()
    {
        $domainId = $this->getRequest()->getParam('domain_id');
        $clientId = pm_Settings::get('global_client_id', '');

        if (empty($clientId)) {
            $this->_status->addError('Bitte zuerst die globale Client ID in den Einstellungen hinterlegen.');
            $this->_redirect('index/settings');
            return;
        }

        $state = base64_encode(json_encode([
            'domain_id' => $domainId,
            'nonce'     => bin2hex(random_bytes(16)),
        ]));
        pm_Settings::set('oauth_state_' . $domainId, $state);

        // Authorization Code Flow: Customer-Admin meldet sich an.
        // AppRoleAssignment.ReadWrite.All erlaubt uns danach im Callback,
        // User.Read.All + Mail.Read programmatisch der App zuzuweisen.
        $params = http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->_getCallbackUrl(),
            'scope'         => 'openid offline_access https://graph.microsoft.com/AppRoleAssignment.ReadWrite.All https://graph.microsoft.com/Application.Read.All',
            'state'         => $state,
            'prompt'        => 'consent',
        ]);

        $this->_helper->redirector->gotoUrl(
            'https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?' . $params
        );
    }

    // ─── OAuth Callback: Token tauschen + App-Rollen automatisch zuweisen ────

    public function oauthCallbackAction()
    {
        $code     = $this->getRequest()->getParam('code', '');
        $state    = $this->getRequest()->getParam('state', '');
        $error    = $this->getRequest()->getParam('error', '');
        $errorMsg = $this->getRequest()->getParam('error_description', '');

        if (!empty($error)) {
            $this->_status->addError('Microsoft Fehler: ' . $errorMsg);
            $this->_redirect('index/domains');
            return;
        }

        $stateData = json_decode(base64_decode($state), true);
        $domainId  = $stateData['domain_id'] ?? '';

        if (empty($domainId) || pm_Settings::get('oauth_state_' . $domainId, '') !== $state) {
            $this->_status->addError('Ungültiger OAuth-State. Bitte erneut versuchen.');
            $this->_redirect('index/domains');
            return;
        }
        pm_Settings::set('oauth_state_' . $domainId, '');

        $clientId     = pm_Settings::get('global_client_id', '');
        $clientSecret = pm_Settings::get('global_client_secret', '');

        try {
            // 1. Authorization Code gegen Access Token tauschen
            $tokenData = $this->_exchangeCode($code, $clientId, $clientSecret);

            // 2. Tenant ID aus JWT auslesen
            $tenantId = $this->_getTenantFromJwt($tokenData['access_token']);
            if (empty($tenantId)) {
                throw new Exception('Tenant ID konnte nicht ermittelt werden.');
            }

            $token = $tokenData['access_token'];

            // 3. Unseren Service Principal im Kunden-Tenant holen (wird beim Login automatisch erstellt)
            $ourSpId = $this->_getServicePrincipalId($token, $clientId);

            // 4. Microsoft Graph Service Principal im Kunden-Tenant holen
            $graphSpId = $this->_getServicePrincipalId($token, '00000003-0000-0000-c000-000000000000');

            // 5. App-Rollen zuweisen (ignoriert Fehler wenn bereits vorhanden)
            $this->_assignAppRole($token, $ourSpId, $graphSpId, 'df021288-bdef-4463-88db-98f22de89214'); // User.Read.All
            $this->_assignAppRole($token, $ourSpId, $graphSpId, '810c84a8-4a9e-49e6-bf7d-12d183f40d01'); // Mail.Read

            pm_Settings::set('domain_tenant_' . $domainId, $tenantId);
            $this->_status->addInfo('✓ Verbindung erfolgreich! Berechtigungen wurden automatisch erteilt.');

        } catch (Exception $e) {
            $this->_status->addError('Verbindungsfehler: ' . $e->getMessage());
        }

        $this->_redirect('index/connect?domain_id=' . $domainId);
    }

    private function _exchangeCode(string $code, string $clientId, string $clientSecret): array
    {
        $url = 'https://login.microsoftonline.com/organizations/oauth2/v2.0/token';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'authorization_code',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $code,
                'redirect_uri'  => $this->_getCallbackUrl(),
                'scope'         => 'openid offline_access https://graph.microsoft.com/AppRoleAssignment.ReadWrite.All',
            ]),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            throw new Exception('Token-Fehler: ' . ($data['error_description'] ?? 'HTTP ' . $httpCode));
        }
        return $data;
    }

    private function _getTenantFromJwt(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) return '';
        $payload = json_decode(base64_decode(str_pad(
            $parts[1],
            strlen($parts[1]) + (4 - strlen($parts[1]) % 4) % 4,
            '='
        )), true);
        return $payload['tid'] ?? '';
    }

    private function _getServicePrincipalId(string $token, string $appId): string
    {
        $url = 'https://graph.microsoft.com/v1.0/servicePrincipals?$filter=' . rawurlencode("appId eq '$appId'") . '&$select=id';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $id   = $data['value'][0]['id'] ?? '';
        if (empty($id)) {
            throw new Exception('Service Principal nicht gefunden für App: ' . $appId);
        }
        return $id;
    }

    private function _assignAppRole(string $token, string $principalId, string $resourceId, string $appRoleId): void
    {
        $url  = 'https://graph.microsoft.com/v1.0/servicePrincipals/' . $principalId . '/appRoleAssignedTo';
        $body = json_encode([
            'principalId' => $principalId,
            'resourceId'  => $resourceId,
            'appRoleId'   => $appRoleId,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 201 = erstellt, 409 = bereits vorhanden → beides OK
        if ($httpCode !== 201 && $httpCode !== 409) {
            $data = json_decode($response, true);
            throw new Exception('AppRole-Zuweisung fehlgeschlagen (HTTP ' . $httpCode . '): ' . ($data['error']['message'] ?? $response));
        }
    }

    private function _getCallbackUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8443';
        return 'https://' . $host . '/modules/o365-exit-migrator/index.php/index/oauth-callback';
    }

    // ─── Postfächer einer Domain via Graph API laden ──────────────────────────

    public function mailboxesAction()
    {
        $this->view->tabs = $this->_getTabs('domains');

        $domainId = $this->getRequest()->getParam('domain_id');
        $domain   = pm_Domain::getByDomainId($domainId);
        $this->view->domain   = $domain;
        $this->view->domainId = $domainId;

        try {
            $token   = new Modules_O365ExitMigrator_O365TokenManager($domainId);
            $graph   = new Modules_O365ExitMigrator_GraphApiClient($token);
            $o365mailboxes = $graph->listMailboxes();
        } catch (Exception $e) {
            $this->_status->addError('Graph API Fehler: ' . $e->getMessage());
            $o365mailboxes = [];
        }

        // Lokale Plesk-Postfächer für Mapping-Dropdown
        $localMailboxes = $this->_getLocalMailboxes($domain->getName());

        $this->view->o365mailboxes  = $o365mailboxes;
        $this->view->localMailboxes = $localMailboxes;
        $this->view->migrateUrl     = pm_Context::getActionUrl('index', 'start-migration');
    }

    // ─── Migration starten ────────────────────────────────────────────────────

    public function startMigrationAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('index/domains');
        }

        $domainId     = $this->getRequest()->getParam('domain_id');
        $o365Email    = $this->getRequest()->getParam('o365_email');
        $pleskEmail   = $this->getRequest()->getParam('plesk_email');
        $dateFrom     = $this->getRequest()->getParam('date_from', '');

        try {
            $jobs   = new Modules_O365ExitMigrator_JobRepository();
            $jobId  = $jobs->create($domainId, $o365Email, $pleskEmail, $dateFrom);

            $runner = new Modules_O365ExitMigrator_GraphMigrationRunner();
            $runner->start($jobId, (int)$domainId, $o365Email, $pleskEmail, $dateFrom);

            $jobs->setPid($jobId, $runner->getLastPid());

            $this->_status->addInfo('Migration gestartet: ' . $o365Email . ' → ' . $pleskEmail);
        } catch (Exception $e) {
            $this->_status->addError('Fehler: ' . $e->getMessage());
        }

        $this->_redirect('index/jobs');
    }

    // ─── Tab: Jobs ────────────────────────────────────────────────────────────

    public function jobsAction()
    {
        $this->view->tabs = $this->_getTabs('jobs');

        $jobs = new Modules_O365ExitMigrator_JobRepository();
        $all  = $jobs->getAll();

        // Status live aktualisieren
        foreach ($all as &$job) {
            if ($job['status'] === 'running') {
                $pid = (int)$job['pid'];
                if ($pid > 0 && !posix_kill($pid, 0)) {
                    $logFile = pm_Context::getVarDir() . 'job_' . $job['id'] . '.log';
                    $lastLine = $this->_lastLine($logFile);
                    $success  = strpos($lastLine, 'Ended on') !== false
                             || strpos($lastLine, 'Transfer Completed') !== false;
                    $jobs->finish($job['id'], $success ? 'done' : 'error');
                    $job['status'] = $success ? 'done' : 'error';
                }
            }
        }
        unset($job);

        $rows = [];
        foreach ($all as $job) {
            $logUrl = pm_Context::getActionUrl('index', 'log') . '?job_id=' . $job['id'];

            switch ($job['status']) {
                case 'running': $badge = '<span style="color:blue">&#9654; läuft</span>';    break;
                case 'done':    $badge = '<span style="color:green">&#10003; fertig</span>'; break;
                case 'error':   $badge = '<span style="color:red">&#10007; Fehler</span>';   break;
                default:        $badge = '<span style="color:#aaa">&#8212; ' . $job['status'] . '</span>';
            }

            $rows[] = [
                'id'         => $job['id'],
                'o365_email' => $job['o365_email'],
                'plesk_email'=> $job['plesk_email'],
                'date_from'  => $job['date_from'] ?: '—',
                'started_at' => $job['started_at'],
                'status'     => $badge,
                'actions'    => '<a href="' . $logUrl . '" class="btn">Log</a>',
            ];
        }

        $list = new pm_View_List_Simple($this->view, $this->_request);
        $list->setData($rows);
        $list->setColumns([
            'o365_email'  => ['title' => 'O365 Postfach',  'noEscape' => false],
            'plesk_email' => ['title' => 'Plesk Postfach', 'noEscape' => false],
            'date_from'   => ['title' => 'Ab Datum',       'noEscape' => false],
            'started_at'  => ['title' => 'Gestartet',      'noEscape' => false],
            'status'      => ['title' => 'Status',         'noEscape' => true],
            'actions'     => ['title' => 'Log',            'noEscape' => true],
        ]);
        $this->view->list     = $list;
        $this->view->jobCount = count($rows);
    }

    // ─── Job-Log anzeigen ─────────────────────────────────────────────────────

    public function logAction()
    {
        $this->view->tabs = $this->_getTabs('jobs');

        $jobId   = $this->getRequest()->getParam('job_id');
        $logFile = pm_Context::getVarDir() . 'job_' . $jobId . '.log';

        $this->view->jobId   = $jobId;
        $this->view->logText = file_exists($logFile) ? file_get_contents($logFile) : '(Kein Log vorhanden)';
    }

    // ─── Tab: Einstellungen ───────────────────────────────────────────────────

    public function settingsAction()
    {
        $this->view->tabs = $this->_getTabs('settings');

        $form = new pm_Form_Simple();

        $form->addElement('text', 'global_client_id', [
            'label'       => 'Azure App Client ID (global)',
            'value'       => pm_Settings::get('global_client_id', ''),
            'required'    => true,
            'description' => 'Client ID der itSieber Multi-Tenant Azure App (einmalig registriert)',
        ]);

        $form->addElement('password', 'global_client_secret', [
            'label'       => 'Azure App Client Secret (global)',
            'value'       => pm_Settings::get('global_client_secret', ''),
            'required'    => false,
            'description' => 'Leer lassen um bestehenden Wert zu behalten',
        ]);

        $form->addControlButtons([
            'sendTitle'  => 'Speichern',
            'cancelLink' => pm_Context::getActionUrl('index', 'index'),
        ]);

        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            pm_Settings::set('global_client_id', $form->getValue('global_client_id'));
            $secret = $form->getValue('global_client_secret');
            if (!empty($secret)) {
                pm_Settings::set('global_client_secret', $secret);
            }
            $this->_status->addInfo('Einstellungen gespeichert.');
            $this->_helper->json(['redirect' => pm_Context::getActionUrl('index', 'settings')]);
        }

        $this->view->form         = $form;
        $this->view->callbackUrl  = $this->_getCallbackUrl();
    }

    // ─── Tab: Update ──────────────────────────────────────────────────────────

    public function updateAction()
    {
        $this->view->tabs = $this->_getTabs('update');

        $updater = new Modules_O365ExitMigrator_Updater();

        if ($this->getRequest()->isPost()) {
            $result = $updater->installUpdate();
            if ($result['success']) {
                $this->_status->addMessage('info', $result['message']);
            } else {
                $this->_status->addMessage('error', $result['message']);
            }
        }

        $this->view->updateInfo      = $updater->checkForUpdate();
        $this->view->currentVersion  = $updater->getCurrentVersion();
    }

    // ─── Hilfsmethoden ───────────────────────────────────────────────────────

    private function _getTabs($active)
    {
        $domId   = $this->getRequest()->getParam('dom_id', '');
        $siteId  = $this->getRequest()->getParam('site_id', '');
        $suffix  = !empty($domId) ? '?dom_id=' . urlencode($domId) . '&site_id=' . urlencode($siteId) : '';

        $tabs = [
            [
                'title'  => 'Domains',
                'action' => 'domains',
                'active' => $active === 'domains',
                'link'   => pm_Context::getActionUrl('index', 'domains') . $suffix,
            ],
            [
                'title'  => 'Jobs',
                'action' => 'jobs',
                'active' => $active === 'jobs',
                'link'   => pm_Context::getActionUrl('index', 'jobs') . $suffix,
            ],
        ];

        if (empty($domId)) {
            $tabs[] = ['title' => 'Einstellungen', 'action' => 'settings', 'active' => $active === 'settings'];
            $tabs[] = ['title' => 'Update',        'action' => 'update',   'active' => $active === 'update'];
        }

        return $tabs;
    }

    private function _getLocalMailboxes($domainName)
    {
        $mailboxes = [];
        try {
            $request = <<<XML
<packet>
    <mail>
        <get_info>
            <filter/>
        </get_info>
    </mail>
</packet>
XML;
            $response = pm_ApiRpc::getService()->call($request);
            if (isset($response->mail->get_info->result)) {
                foreach ($response->mail->get_info->result as $result) {
                    if (isset($result->mailname)) {
                        $addr = (string)$result->mailname . '@' . $domainName;
                        $mailboxes[$addr] = $addr;
                    }
                }
            }
        } catch (Exception $e) {
            // ignore
        }
        return $mailboxes;
    }

    private function _getPleskMailPassword($email)
    {
        list($mailname, $domainName) = explode('@', $email, 2);
        try {
            $request = <<<XML
<packet>
    <mail>
        <get_info>
            <filter>
                <mailname>{$mailname}</mailname>
            </filter>
            <mailbox/>
        </get_info>
    </mail>
</packet>
XML;
            $response = pm_ApiRpc::getService()->call($request);
            if (isset($response->mail->get_info->result->mailbox->password)) {
                return (string)$response->mail->get_info->result->mailbox->password;
            }
        } catch (Exception $e) {
            // ignore
        }
        return '';
    }

    private function _lastLine($file)
    {
        if (!file_exists($file)) {
            return '';
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $lines ? end($lines) : '';
    }
}
