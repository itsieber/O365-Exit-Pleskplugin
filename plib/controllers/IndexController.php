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

    // ─── Tab: Domains ────────────────────────────────────────────────────────

    public function domainsAction()
    {
        $this->view->tabs = $this->_getTabs('domains');

        $rows = [];
        foreach (pm_Domain::getAllDomains() as $domain) {
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

        $domainId   = $this->getRequest()->getParam('domain_id');
        $domain     = pm_Domain::getByDomainId($domainId);
        $this->view->domain = $domain;

        $form = new pm_Form_Simple();

        $form->addElement('text', 'tenant_id', [
            'label'       => 'Azure Tenant ID',
            'value'       => pm_Settings::get('domain_tenant_' . $domainId, ''),
            'required'    => true,
            'description' => 'z.B. xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx (Azure Portal → Entra ID → Übersicht)',
        ]);

        $form->addElement('text', 'client_id', [
            'label'       => 'App Client ID (Application ID)',
            'value'       => pm_Settings::get('domain_client_' . $domainId, ''),
            'required'    => true,
            'description' => 'Azure Portal → App-Registrierungen → deine App → Anwendungs-ID',
        ]);

        $form->addElement('password', 'client_secret', [
            'label'       => 'Client Secret',
            'value'       => pm_Settings::get('domain_secret_' . $domainId, ''),
            'required'    => false,
            'description' => 'Leer lassen um bestehenden Wert zu behalten',
        ]);

        $form->addControlButtons([
            'sendTitle'  => 'Speichern & Verbinden',
            'cancelLink' => pm_Context::getActionUrl('index', 'domains'),
        ]);

        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            pm_Settings::set('domain_tenant_' . $domainId, $form->getValue('tenant_id'));
            pm_Settings::set('domain_client_' . $domainId, $form->getValue('client_id'));

            $secret = $form->getValue('client_secret');
            if (!empty($secret)) {
                pm_Settings::set('domain_secret_' . $domainId, $secret);
            }

            // Verbindung testen
            try {
                $token = new Modules_O365ExitMigrator_O365TokenManager($domainId);
                $token->getAccessToken();
                $this->_status->addInfo('Verbindung zu Office 365 erfolgreich.');
            } catch (Exception $e) {
                $this->_status->addError('OAuth-Fehler: ' . $e->getMessage());
            }

            $this->_redirect('index/domains');
        }

        $this->view->form = $form;
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
            $token = new Modules_O365ExitMigrator_O365TokenManager($domainId);
            $accessToken = $token->getAccessToken();

            // Plesk-Passwort des Postfachs auslesen
            $pleskPass = $this->_getPleskMailPassword($pleskEmail);
            if (empty($pleskPass)) {
                throw new Exception('Konnte Passwort für ' . $pleskEmail . ' nicht ermitteln.');
            }

            $jobs   = new Modules_O365ExitMigrator_JobRepository();
            $jobId  = $jobs->create($domainId, $o365Email, $pleskEmail, $dateFrom);

            $runner = new Modules_O365ExitMigrator_ImapsyncRunner();
            $runner->start($jobId, $o365Email, $accessToken, $pleskEmail, $pleskPass, $dateFrom);

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
        $this->view->list = $list;
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

        $form->addElement('text', 'imapsync_path', [
            'label'       => 'imapsync Pfad',
            'value'       => pm_Settings::get('imapsync_path', '/usr/bin/imapsync'),
            'required'    => true,
            'description' => 'Absoluter Pfad zur imapsync-Binary',
        ]);

        $form->addElement('text', 'imap_host', [
            'label'       => 'Lokaler IMAP-Host',
            'value'       => pm_Settings::get('imap_host', '127.0.0.1'),
            'required'    => true,
            'description' => 'Hostname/IP des Plesk-Mailservers',
        ]);

        $form->addElement('text', 'imap_port', [
            'label'       => 'Lokaler IMAP-Port',
            'value'       => pm_Settings::get('imap_port', '993'),
            'required'    => true,
        ]);

        $form->addControlButtons([
            'sendTitle'  => 'Speichern',
            'cancelLink' => pm_Context::getActionUrl('index', 'index'),
        ]);

        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            pm_Settings::set('imapsync_path', $form->getValue('imapsync_path'));
            pm_Settings::set('imap_host',     $form->getValue('imap_host'));
            pm_Settings::set('imap_port',     $form->getValue('imap_port'));
            $this->_status->addInfo('Einstellungen gespeichert.');
            $this->_helper->json(['redirect' => pm_Context::getActionUrl('index', 'settings')]);
        }

        $this->view->form = $form;
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
        return [
            ['title' => 'Domains',      'action' => 'domains',  'active' => $active === 'domains'],
            ['title' => 'Jobs',         'action' => 'jobs',     'active' => $active === 'jobs'],
            ['title' => 'Einstellungen','action' => 'settings', 'active' => $active === 'settings'],
            ['title' => 'Update',       'action' => 'update',   'active' => $active === 'update'],
        ];
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
