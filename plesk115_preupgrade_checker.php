#!/usr/local/psa/admin/bin/php
<?php
/**
 * Due to the changes in business model in PP10 release, not all previous accounts settings 
 * will be portable from the previous Plesk releases. Some business schemas will be lost for hosters 
 * and their clients. This tool could be launched prior to upgrade for the purpose of getting 
 * a report on potential problems with the upgrade. Based on the report a hoster can decide 
 * whether upgrade to PP10 is suitable for him.
 *
 * Requirements: script supports PHP4/PHP5 in case where installed PHP4 only
 */

define('APP_PATH', dirname(__FILE__));
define('DEBUG', 0); // allow to dump sql logs to output
define('PRE_UPGRADE_SCRIPT_VERSION', '11.5.30.10'); //script version
define('PLESK_VERSION', '11.5.30'); // latest Plesk version
define('AI_VERSION', '3.15.15'); // latest autoinstaller version
@date_default_timezone_set(@date_default_timezone_get());

if (!defined('PHP_EOL')) {
    define('PHP_EOL', "\n", true);
}

$phpType = php_sapi_name();
if (substr($phpType, 0, 3) == 'cgi') {
    //:INFO: set max execution time 1hr
    @set_time_limit(3600);
}

class Plesk10BusinessModel
{
    function validate()
    {
        if (!PleskInstallation::isInstalled()) {
            //:INFO: Plesk installation is not found. You will have no problems with upgrade, go on and install Plesk Panel 10
            return;
        }

        if (PleskVersion::is8x() || PleskVersion::is9x()) {
            //:INFO: Clients owned by Administrator (Client control over domain resources)
            $this->_diagnoseClientsHaveMoreDomainsThanOneOwnedByAdmin();
            $this->_diagnoseClientsHaveDomainAdministratorsOwnedByAdmin();
            $this->_diagnoseClientsHaveNoDomainsOwnedByAdmin();
            $this->_diagnoseClientPermissions();
        }

        if (PleskVersion::is9x()) {
            //:INFO: Clients owned by Resellers (Client control over domain resources)
            $this->_diagnoseClientsHaveMoreDomainsThanOneOwnedByReseller();
            $this->_diagnoseClientsHaveDomainAdministratorsOwnedByReseller();
            $this->_diagnoseClientsHaveNoDomainsOwnedByReseller();

            //:INFO: Domain Administrators owned by Admin
            $this->_diagnoseDomainAdmsOwnedByAdministrator();

            //:INFO: Domain Administrators owned by Resellers
            $this->_diagnoseDomainAdmsOwnedByResellers();

            //:INFO: Domain owned by non-existent Clients
            $this->_diagnoseDomainOwnersDontExist();
        }
    }

    /**
     * Domain Administrators owned by Hoster
     */
    function _diagnoseDomainAdmsOwnedByAdministrator()
    {
        //:INFO: Admin can create own domains since Plesk 9.x
        Log::step('Calculate domains belonging to admin with defined domain administrators', true);

        $domains = $this->_getAdminDomains();

        $details = 'The following domains have domain administrator: ' . PHP_EOL . PHP_EOL;
        foreach ($domains as $domainOwnedByAdmin) {
            $details .= "'{$domainOwnedByAdmin['name']}'" . PHP_EOL;
        }

        if (sizeof($domains)>0) {
            $logPath = APP_PATH.'/admin_domains_with_dom_admins.log';
            Log::write($logPath, $details, 'w');

            $warn = 'You have '.sizeof($domains).' domains with separate Domain Administrators. ';
            $warn .= 'In Plesk 10.x these users will see all your domains. ';
            $warn .= 'You should consider converting them to Customers after upgrade to avoid security problem.';
            Log::warning($warn);

            Log::info('See details in log ' . $logPath);
            Log::resultWarning();

            return;
        }
        Log::resultOk();
    }

    /**
     * Domain Administrators owned by Resellers
     */
    function _diagnoseDomainAdmsOwnedByResellers()
    {
        //:INFO: Reseller role was defined since 9.0
        Log::step('Calculate domains belonging to resellers with defined domain administrators', true);

        $resellerDomains = $this->_getResellerDomains();
        $details = '';
        $totalDomains = 0;
        foreach ($resellerDomains as $reseller => $domains) {
            $details .= "Reseller '{$reseller}' has " . sizeof($domains) . " domain administrators" . PHP_EOL . PHP_EOL;
            $totalDomains += sizeof($domains);
        }

        if (sizeof($resellerDomains)>0) {
            $logPath = APP_PATH.'/reseller_domains_with_dom_admins.log';
            Log::write($logPath, $details, 'w');

            $warn = 'You have '.sizeof($resellerDomains).' resellers with '.$totalDomains.' domains with separate domain administrators. ';
            $warn .= 'In Plesk 10.x these users will see all your domains of their Resellers. ';
            $warn .= 'You should consider converting them to customers after upgrade to avoid security problem.';
            Log::warning($warn);

            Log::info('See details in log ' . $logPath);
            Log::resultWarning();

            return;
        }
        Log::resultOk();
    }

    /**
     * The diagnosed situation is:
     * Client limit has more than 1 domain
     * Client can create domains
     * Client can manage domain limits
     */
    function _diagnoseClientsHaveMoreDomainsThanOneOwnedByAdmin()
    {
        Log::step('Calculate clients owned by administrator who have more than a one domain', true);

        $clientsOwnedByAdmin = $this->_getClientsOwnedByAdmin();
        $total = sizeof($clientsOwnedByAdmin);
        $details = '';

        foreach ($clientsOwnedByAdmin as $clientOwnedByAdmin) {
            $details .= "Client '{$clientOwnedByAdmin['pname']}' has {$clientOwnedByAdmin['count_dom']} domains" . PHP_EOL . PHP_EOL;
        }

        //:INFO: count of clients > 0
        if ($total) {
            $logPath = APP_PATH.'/admin_clients_have_domains.log';
            Log::write($logPath, $details, 'w');

            $warn = 'You have '.$total.' clients who are free to manage resources on their domains themselves within the resource limits that you gave them.';
            $warn.= ' In Plesk 10.x the resources are defined in a subscription.';
            $warn.= ' The Customer cannot redistribute resources between his/her subscriptions.';
            $warn.= ' If you want to leave the same degree of flexibility for these Customers, you should consider converting them to Resellers after upgrade.';
            Log::warning($warn);

            Log::info('See details in log ' . $logPath);
            Log::resultWarning();

            return;
        }

        Log::resultOk();
    }

    /**
     * The diagnosed situation is:
     * Client has created Domain Administrators for several domains
     */
    function _diagnoseClientsHaveDomainAdministratorsOwnedByAdmin()
    {
        Log::step('Calculate clients owned by administrator who have domain administrators', true);

        $clients = $this->_getClientsHaveDomAdminsOwnedByAdmin();

        $details = '';
        foreach ($clients as $client) {
            $details .= "Client '{$client['pname']}' has {$client['count_dom_adm']} domain administrators" . PHP_EOL . PHP_EOL;
        }

        if (sizeof($clients)) {
            $logPath = APP_PATH.'/admin_clients_have_domain_administators.log';
            Log::write($logPath, $details, 'w');

            $warn = 'Your have '.sizeof($clients).' clients with Domain Administrators defined on more than one domain.';
            $warn.= ' After transitions these Clients will have problems, because in Plesk 10.x users belonging to a Customer have access to all Customer’s subscriptions.';
            $warn.= ' You will avoid the problem if after upgrade the Clients will be upgraded to Resellers and Domain Administrators to the Customers.';
            Log::warning($warn);

            Log::info('See details in log ' . $logPath);
            Log::resultWarning();

            return;
        }
        Log::resultOk();
    }

    /**
     * The diagnosed situation is:
     * Client has no domains
     */
    function _diagnoseClientsHaveNoDomainsOwnedByAdmin()
    {
        Log::step('Calculate clients owned by administrator who have no domains', true);

        $clients = $this->_getClientsHaveNoDomainsOwnedByAdmin();

        $details = '';
        foreach ($clients as $client) {
            $details .= "Client '{$client['pname']}' has no domains" . PHP_EOL . PHP_EOL;
        }

        if (sizeof($clients)) {
            $logPath = APP_PATH.'/admin_clients_have_no_domains.log';
            Log::write($logPath, $details, 'w');

            $warn  = 'Your have ' . sizeof($clients) . ' clients with no domains defined.';
            $warn .= ' After transitions these Clients will not be able to login into the control panel until you create a subscription for them.';
            $warn .= ' An alternative solution is upgrading them to resellers after conversion.';
            Log::warning($warn);

            Log::info('See details in log ' . $logPath);
            Log::resultWarning();

            return;
        }
        Log::resultOk();
    }

    /**
     * The diagnosed situation is:
     * Client has default permissions
     */
    function _diagnoseClientPermissions()
    {
        Log::step('Calculate clients who have default permissions', true);

        $db = PleskDb::getInstance();

        $where = 'WHERE perm_id is NULL';
        if (PleskVersion::is9x()) {
            $where = "WHERE perm_id is NULL AND id<>".$this->_getAdminId();
        }
        //Get client list where perm_id is null. It means that client never modified permissions and have default permissions
        $sql = "SELECT clients.pname FROM clients {$where}";
        $clients = $db->fetchAll($sql);

        if (sizeof($clients)) {
            $details = 'Client list where perm_id is null. It means that client never modified permissions and has default permissions' . PHP_EOL . PHP_EOL;
            foreach ($clients as $client) {
                $details .= "Client '{$client['pname']}' has default permissions" . PHP_EOL . PHP_EOL;
            }
            $logPath = APP_PATH.'/clients_have_default_permissions.log';
            Log::write($logPath, $details, 'w');

            $warn = 'You have client '.sizeof($clients).' with default permissions.';
            $warn .= ' Due to the changes in business model in PP10 release, default permissions are different from other versions. ';
            $warn .= 'You should go to the Client/Reseller permission page and save changes in order to permission values were saved in database.';
            Log::warning($warn);

            Log::info('See details in log ' . $logPath);
            Log::resultWarning();

            return;
        }
        Log::resultOk();
    }

    /**
     * The diagnosed situation is:
     * Client limit has more than 1 domain
     * Client can create domains
     * Client can manage domain limits
     */
    function _diagnoseClientsHaveMoreDomainsThanOneOwnedByReseller()
    {
        Log::step('Calculate clients owned by reseller who have more than a one domain', true);

        $resellers = $this->_getClientsOwnedByReseller();

        $totalResellers = sizeof($resellers);
        $totalClients = 0;
        $details = '';
        foreach ($resellers as $reseller => $clients) {
            $details .= "Reseller with login '{$reseller}' has " . sizeof($clients) . ' clients' . PHP_EOL;
            $totalClients += sizeof($clients);
            foreach ($clients as $client) {
                $details .= "----Client '{$client['pname']}' has {$client['count_dom']} domains" . PHP_EOL;
            }
        }

        if ($totalResellers) {
            $logPath = APP_PATH.'/reseller_clients_have_domains.log';
            Log::write($logPath, $details, 'w');

            $warn = 'You have '.$totalResellers.' resellers with '.$totalClients.' clients who are free to manage resources on their domains themselves within the resource limits that you gave them.';
            $warn.= ' In Plesk 10.x the resources are defined in a subscription.';
            $warn.= ' The Customer cannot redistribute resources between his/her subscriptions.';
            $warn.= ' After upgrade you will be suggested to redistribute Clients’ resources between the existing subscriptions (current domains).';
            Log::warning($warn);

            Log::info('See details in log ' . $logPath);
            Log::resultWarning();

            return;
        }

        Log::resultOk();
    }

    /**
     * The diagnosed situation is:
     * Client has created Domain Administrators for several domains
     */
    function _diagnoseClientsHaveDomainAdministratorsOwnedByReseller()
    {
        Log::step('Calculate clients owned by reseller who have domain administrators', true);

        $resellers = $this->_getClientsWithDomAdminsOwnedByReseller();

        $totalResellers = sizeof($resellers);
        $totalClients = 0;
        $details = '';
        foreach ($resellers as $reseller => $clients) {
            $details .= "Reseller with login '{$reseller}' has " . sizeof($clients) . ' clients' . PHP_EOL;
            $totalClients += sizeof($clients);
            foreach ($clients as $client) {
                $details .= "----Client '{$client['pname']}' has {$client['count_dom_adm']} domain administrators" . PHP_EOL;
            }
        }

        if ($totalResellers) {
            $logPath = APP_PATH.'/reseller_clients_have_domain_administators.log';
            Log::write($logPath, $details, 'w');

            $warn = 'You have '.$totalResellers.' resellers with '.$totalClients.' clients with Domain Administrators defined on more than one domain.';
            $warn.= ' After transitions these Clients will have problems, because in Plesk 10.x users belonging to a Customer have access to all Customer’s subscriptions.';
            $warn.= ' Probably you should consult with your resellers of the path forward for these customers.';
            Log::warning($warn);

            Log::info('See details in log ' . $logPath);
            Log::resultWarning();

            return;
        }

        Log::resultOk();
    }

    /**
     * The diagnosed situation is:
     * Client has no domains
     */
    function _diagnoseClientsHaveNoDomainsOwnedByReseller()
    {
        Log::step('Calculate clients owned by reseller who have no domains', true);

        $resellers = $this->_getClientsHaveNoDomainsOwnedByReseller();

        $totalResellers = sizeof($resellers);
        $totalClients = 0;
        $details = '';
        foreach ($resellers as $reseller => $clients) {
            $details .= "Reseller with login '{$reseller}' has " . sizeof($clients) . ' clients' . PHP_EOL;
            $totalClients += sizeof($clients);
            foreach ($clients as $client) {
                $details .= "----Client '{$client['pname']}' has no domains" . PHP_EOL;
            }
        }

        if ($totalResellers) {
            $logPath = APP_PATH.'/reseller_clients_have_no_domains.log';
            Log::write($logPath, $details, 'w');

            $warn = 'You have '.$totalResellers.' resellers with '.$totalClients.' clients that have no domains defined.';
            $warn.= ' After transitions these Clients will not be able to login into the control panel until you or Reseller create a subscription for them.';
            Log::warning($warn);

            Log::info('See details in log ' . $logPath);
            Log::resultWarning();

            return;
        }

        Log::resultOk();
    }

    function _diagnoseDomainOwnersDontExist()
    {
	Log::step('Checking if there are domains owned by non-existent users', true);
	$db = PleskDb::getInstance();
	$sql = 'SELECT name FROM domains AS d LEFT JOIN clients AS c ON c.id = d.cl_id WHERE c.id IS NULL;';
        $domains = $db->fetchAll($sql);

	$details = 0;
	$totalDomains = 0;
	foreach ($domains as $key => $domain)
	{
	    $details .= "----Domain '{$domain['name']}' belongs to owner that does not exist" . PHP_EOL;
	    $totalDomains++;
	}

	if ($totalDomains > 0)
	{
            $logPath = APP_PATH.'/domain_owners_dont_exists.log';
            Log::write($logPath, $details, 'w');
	    $warn = 'You have '.$totalDomains.' domains whose owners do not exist.';
	    Log::warning($warn);
            Log::info('See details in log ' . $logPath);
            Log::resultWarning();
            return;
	}

        Log::resultOk();
    }

    function _getClientsOwnedByAdmin()
    {
        $db = PleskDb::getInstance();
        $filterOwnedByAdmin = '';
        if (PleskVersion::is9x()) {
            $filterOwnedByAdmin = "WHERE type='client' AND parent_id=".$this->_getAdminId();
        }
        $sql = 'SELECT clients.id, clients.pname, clients.perm_id, count(domains.id) as count_dom FROM clients
            INNER JOIN domains ON(domains.cl_id = clients.id)
            '.$filterOwnedByAdmin.'
            GROUP BY clients.id, clients.pname, clients.perm_id
            HAVING count(domains.id) > 1
        ';
        $clients = $db->fetchAll($sql);

        foreach ($clients as $key => $client) {
            if (empty($client['perm_id'])) {
                //:INFO: client permissions based on default template
                unset($clients[$key]);
                continue;
            }

            $sql = "SELECT count(*) FROM Permissions
                WHERE id={$client['perm_id']} AND value='true' AND
                (permission = 'create_domains' OR permission = 'change_limits')
            ";
            $count = $db->fetchOne($sql);

            //:INFO: Client has no permissions and we unset him.
            if ($count != 2) {
                unset($clients[$key]);
            }
        }
        return $clients;
    }

    function _getClientsHaveDomAdminsOwnedByAdmin()
    {
        $filterOwnedByAdmin = '';
        if (PleskVersion::is9x()) {
            $filterOwnedByAdmin = "WHERE type='client' AND parent_id=".$this->_getAdminId();
        }

        $db = PleskDb::getInstance();
        $sql ='SELECT clients.id, clients.pname, count(domains.name) AS count_dom_adm
            FROM ((clients
            INNER JOIN domains ON (clients.id=domains.cl_id))
            INNER JOIN dom_level_usrs ON(domains.id=dom_level_usrs.dom_id))
            '.$filterOwnedByAdmin.'
            GROUP BY clients.id, clients.pname
            HAVING count(domains.name) > 1
        ';
        $clients = $db->fetchAll($sql);

        return $clients;
    }

    function _getClientsHaveNoDomainsOwnedByAdmin()
    {
        $filterOwnedByAdmin = '';
        if (PleskVersion::is9x()) {
            $filterOwnedByAdmin = "AND type='client' AND parent_id=".$this->_getAdminId();
        }
        $db = PleskDb::getInstance();
        $sql = 'SELECT clients.id, clients.pname FROM clients
            WHERE id NOT IN (SELECT cl_id FROM domains) '.$filterOwnedByAdmin.'
        ';
        $clients = $db->fetchAll($sql);
        return $clients;
    }

    function _getClientsOwnedByReseller()
    {
        $db = PleskDb::getInstance();
        $sql = "SELECT clients.id, clients.login, clients.pname FROM clients WHERE type='reseller'";
        $resellers = $db->fetchAll($sql);

        //:INFO: Get list of resellers with clients who have more than one domain
        $resellerMatched = array();
        foreach ($resellers as $reseller) {
            $sql = 'SELECT clients.id, clients.login, clients.perm_id, clients.pname, count(domains.id) as count_dom FROM clients
                INNER JOIN domains ON(domains.cl_id = clients.id AND clients.parent_id = '.$reseller['id'].')
                GROUP BY clients.id, clients.login, clients.perm_id, clients.pname
                HAVING count(domains.id) > 1
            ';
            $clients = $db->fetchAll($sql);
            if (sizeof($clients) > 0) {
                $resellerMatched[$reseller['login']] = $clients;
            }
        }

        //:INFO: Check that clients has permissions 'create_domains' and 'change_limits'
        foreach ($resellerMatched as $key => $clients) {
            foreach ($clients as $cl_key => $client) {
                if (empty($client['perm_id'])) {
                    //:INFO: client permissions based on default template
                    unset($resellerMatched[$key][$cl_key]);
                    continue;
                }

                $sql = "SELECT count(*) FROM Permissions
                    WHERE id={$client['perm_id']} AND value='true' AND
                    (permission = 'create_domains' OR permission = 'change_limits')
                ";
                $count = $db->fetchOne($sql);
                if ($count != 2) {
                    //:INFO: Client has no permissions and we unset him.
                    unset($resellerMatched[$key][$cl_key]);
                }
            }
            //:INFO: unset reseller if client list is empty
            if (!sizeof($resellerMatched[$key])) {
                unset($resellerMatched[$key]);
            }
        }

        return $resellerMatched;
    }

    function _getClientsWithDomAdminsOwnedByReseller()
    {
        $db = PleskDb::getInstance();
        $sql = "SELECT clients.id, clients.login, clients.pname FROM clients WHERE type='reseller'";
        $resellers = $db->fetchAll($sql);

        $resellerMatched = array();
        foreach ($resellers as $reseller) {
            $sql ='SELECT clients.id, clients.pname, count(domains.name) AS count_dom_adm
                FROM ((clients
                INNER JOIN domains ON (clients.id=domains.cl_id AND clients.parent_id = '.$reseller['id'].'))
                INNER JOIN dom_level_usrs ON(domains.id=dom_level_usrs.dom_id))
                GROUP BY clients.id, clients.pname
                HAVING count(domains.name) > 1
            ';
            $clients = $db->fetchAll($sql);

            if (sizeof($clients) > 0) {
                $resellerMatched[$reseller['login']] = $clients;
            }
        }

        return $resellerMatched;
    }

    function _getClientsHaveNoDomainsOwnedByReseller()
    {
        $db = PleskDb::getInstance();
        $sql = "SELECT clients.id, clients.login, clients.pname FROM clients WHERE type='reseller'";
        $resellers = $db->fetchAll($sql);

        $resellerMatched = array();
        foreach ($resellers as $reseller) {
            $sql = 'SELECT clients.id, clients.pname FROM clients
                WHERE parent_id='.$reseller['id'].' AND id NOT IN (SELECT cl_id FROM domains)
            ';
            $clients = $db->fetchAll($sql);

            if (sizeof($clients) > 0) {
                $resellerMatched[$reseller['login']] = $clients;
            }
        }
        return $resellerMatched;
    }

    function _getAdminDomains()
    {
        $db = PleskDb::getInstance();
        $sql = 'SELECT domains.name FROM domains
            INNER JOIN dom_level_usrs ON(domains.id=dom_level_usrs.dom_id)
            WHERE domains.cl_id='.$this->_getAdminId()
        ;

        $domains = $db->fetchAll($sql);
        if (sizeof($domains) <= 1) {
            return array();
        }
        return $domains;
    }

    function _getResellerDomains()
    {
        $db = PleskDb::getInstance();
        $sql = "SELECT clients.id, clients.login FROM clients WHERE type='reseller'";
        $resellers = $db->fetchAll($sql);

        $resellerMatched = array();
        foreach ($resellers as $reseller) {
            $sql = "SELECT domains.name FROM domains
                INNER JOIN dom_level_usrs ON(domains.id=dom_level_usrs.dom_id)
                WHERE cl_id = {$reseller['id']}";
            $domains = $db->fetchAll($sql);

            if (sizeof($domains) > 1) {
                $resellerMatched[$reseller['login']] = $domains;
            }
        }
        return $resellerMatched;
    }

    function _getAdminId()
    {
        $db = PleskDb::getInstance();
        $sql = "SELECT id FROM clients WHERE type='admin'";
        $adminId = $db->fetchOne($sql);

        if (empty($adminId)) {
            Log::fatal('Unable to find Plesk administrator. Please check SQL: ' . $sql);
        }

        return $adminId;
    }

    function _getAdminEmail()
    {
    	$db = PleskDb::getInstance();
    	$sql = "SELECT val FROM misc WHERE param='admin_email'";
    	$adminEmail = $db->fetchOne($sql);

    	return $adminEmail;
    }

    //:INFO: Domain's type can be 'none','vrt_hst','std_fwd','frm_fwd'
    function _getDomainsByHostingType($type = 'vrt_hst')
    {
    	$db = PleskDb::getInstance();
    	$sql = 'SELECT name FROM domains WHERE htype="' . $type . '"';
    	$domains = $db->fetchAll($sql);

    	return $domains;
    }
}

class Plesk10Requirements
{
    function validate()
    {
        //:INFO: Make sure that offline management is switched off before upgrading to Plesk 10.x
        if (PleskInstallation::isInstalled() && (PleskVersion::is8x() || PleskVersion::is9x())) {
            $this->_checkOfflineManagement();
        }

        //:INFO: Check that Linux security module is swicthed off
        $this->_checkApparmorService();

        //:INFO: Server should have properly configured hostname and it should be resolved locally
        $this->_resolveHostname();

        //:INFO: Hard require for innodb turned on
        $this->_checkInnodbEngineTurnedOn();

		//:INFO: Validate PHP version for webmails
        $this->_checkPhpForWebmails();
    }

    function _checkInnodbEngineTurnedOn()
    {
        Log::step('Checking if the MySQL engine InnoDB is allowed...', true);
        if (Util::isWindows()) {
            Log::resultOk();
            return;
        }

        $db = PleskDb::getInstance();
        $row = $db->fetchRow("SHOW VARIABLES LIKE 'have_innodb'");
        if (@array_key_exists ('Value', $row)) {
            $have_innodb = $row['Value'];
	    } else {
            $errMsg = 'Unable to find InnoDB engine support.';
            Log::warning($errMsg);
            Log::resultWarning();
            return;
        }

        if ($have_innodb == "YES") {
            Log::resultOk();
            return;
        }

        $errMsg = 'The InnoDB engine is not allowed by MySQL. Panel upgrade is not possible.' .PHP_EOL
            .'Please remove the option "skip-innodb" from /etc/my.cnf (or /etc/mysql/my.cnf) and restart MySQL service. This will allow InnoDB and make the upgrade possible.';
        Log::error($errMsg);
        Log::resultError();
    }

    function _checkPhpForWebmails()
    {
		Log::step('Checking the compatibility of the installed PHP version with the webmail software...', true);

        if (Util::isWindows()) {
            Log::resultOk();
            return;
		}

		$phpWarn = 'After the Panel upgrade, the Panel-managed webmail software will require the following PHP versions: RoundCube - PHP 5.2 or later, Horde - PHP 5.3 or later, AtMail - any PHP version. Note that your version of PHP might become incompatible with the Panel-managed webmail afer the upgrade - in this case, you can switch to AtMail or upgrade PHP.';
        if (false === PackageManager::isInstalled('psa-atmail')) {
			$phpWarn .= PHP_EOL . 'AtMail is not installed on your server.';

			if (version_compare('11.5.21', PleskVersion::getVersion(), '>')) {
				$phpWarn .= ' You can install the AtMail webmail before the Panel upgrade. As an alternative, you can install a newer PHP version that will be compatible with your webmail after the upgrade.';
			}
		} else {
			$phpWarn .= PHP_EOL . 'AtMail is installed on your server.';
		}

        $phpVersion = PleskComponent::getPackageVersion('php');
        if (null == $phpVersion) {
            Log::warning($phpWarn . PHP_EOL . 'Unable to determine the installed PHP version.');
            Log::resultWarning();
            return;
		}
        $phpWarn .= PHP_EOL . "The installed PHP version is {$phpVersion}";

        $hordeSatisfiedPhp = true;
        if (false !== PackageManager::isInstalled('psa-horde') && version_compare('5.3.0', $phpVersion, '>')) {
            $phpWarn .= PHP_EOL . 'After the upgrade you will not be able to use the Horde webmail because it requires PHP 5.3 or later.';
            $hordeSatisfiedPhp = false;
        }

        $roundCubeSatisfiedPhp = true;
        if (false !== PackageManager::isInstalled('plesk-roundcube') && version_compare('5.2.0', $phpVersion, '>')) {
            $phpWarn .= PHP_EOL . 'After the upgrade you will not be able to use the RoundCube webmail because it requires PHP 5.2 or later.';
            $roundCubeSatisfiedPhp = false;
		}

		if ($hordeSatisfiedPhp && $roundCubeSatisfiedPhp) {
			Log::info($phpWarn);
			Log::resultOk();
		} else {
			Log::warning($phpWarn);
			Log::resultWarning();
		}
    }

    function _checkApparmorService()
    {
        if (Util::isLinux()) {
            Log::step('Detecting if the apparmor service is switched off...', true);

            $apparmorPath = '/etc/init.d/apparmor';
            if (file_exists($apparmorPath)) {
            	$apparmor_status = Util::exec('/etc/init.d/apparmor status', $code);
            	if (preg_match('/(complain|enforce)/', $apparmor_status)) {
                	$warn = 'The \'Apparmor\' security module for the Linux kernel is turned on. ';
                	$warn .= 'Turn the module off before continuing work with Parallels Plesk Panel. Please check http://kb.parallels.com/en/112903 for more details.';
                	Log::warning($warn);
                	Log::resultWarning();
                	return;
            	}
            }
            Log::resultOk();
        }
    }

    function _checkOfflineManagement()
    {
        Log::step('Detect virtualization', true);

        //:INFO: There is no ability to detect offline management inside VZ container
        if (Util::isVz()) {
            $warn = 'Virtuozzo is detected in your system. ';
            $warn .= 'Make sure that offline management is switched off for the container before installing or upgrading to ' . PleskVersion::getLatestPleskVersionAsString();
            Log::info($warn);

            return;
        }

        Log::resultOk();
    }

    function _resolveHostname()
    {
        Log::step('Validate hostname', true);

        $hostname = Util::getHostname();

        //Get the IPv address corresponding to a given Internet host name
        $ip = gethostbyname($hostname);
        if (!PleskValidator::isValidIp($ip)) {
            $warn = "Hostname '{$hostname}' is not resolved locally.";
            $warn .= 'Make sure that server should have properly configured hostname and it should be resolved locally before installing or upgrading to Plesk Billing';
            Log::warning($warn);
            Log::resultWarning();

            return;
        }
        Log::resultOk();
    }
}

class Plesk10MailServer
{
    function validate()
    {
        if (!PleskInstallation::isInstalled()) {
            return;
        }

        $this->_checkPostfixDnsLookup();
    }

    function _checkPostfixDnsLookup()
    {
        if (Util::isLinux()) {
            Log::step('Detecting if mail server type is Postfix...', true);

            if ($this->_isPostfix()) {
                Log::info('Postfix is current mail server');
                $cmd = Util::lookupCommand('postconf') . ' disable_dns_lookups';
                $output = Util::exec($cmd, $code);
                if (preg_match('/disable_dns_lookups[\s]{0,}=[\s]{0,}yes/', $output)) {
                    $warn = "Parameter 'disable_dns_lookups' is disabled in Postfix configuration (/etc/postfix/main.cf). ";
                    $warn .= "By default this parameter is set to 'no' by Parallels Plesk. ";
                    $warn .= "Need to set param value disable_dns_lookups=yes";
                    Log::warning($warn);
                    Log::resultWarning();

                    return;
                }
            } else {
                Log::info('Qmail is current mail server');
            }

            Log::resultOk();
        }
    }

    function _isPostfix()
    {
        $res = Util::lookupCommand(
            'postconf',
            '/usr/bin:/usr/local/bin:/usr/sbin:/bin:/sbin:/usr/local/sbin',
            false
        );

        return $res;
    }

    function CurrentWinMailServer()
    {
    	if (Util::isWindows()) {
    		$currentMailServer = Util::regQuery('\PLESK\PSA Config\Config\Packages\mailserver', '/ve', true);
    		Log::info('Current mail server is: ' . $currentMailServer);
    		return $currentMailServer;
    	}
    }

}

class Plesk10Skin
{
    function validate()
    {
        if (!PleskInstallation::isInstalled()) {
            //:INFO: Plesk installation is not found. You will have no problems with upgrade, go on and install Plesk Panel 10
            return;
        }

        if (PleskVersion::is8x() || PleskVersion::is9x()) {
            Log::step('Notify about skins in ' . PleskVersion::getLatestPleskVersionAsString());

            $warn = 'Parallels Plesk Panel 10 is shipped with only one graphical user interface appearance theme, ';
            $warn .= 'so all the appearance settings will be ignored after upgrade and the default theme will be used for all users. ';
            $warn .= 'If you want to change the default Panel 10 appearance, create and use custom appearance themes as described ';
            $warn .= 'in the Guide to Customizing Panel Appearance and Branding (http://www.parallels.com/products/plesk/documentation/). ';
            $warn .= 'Deploying custom themes is available since Plesk 10.1.';
            Log::info($warn);
        }
    }
}

class Plesk10Permissions
{
    function validate()
    {
        $this->_validatePHPSessionDir();
    }

    function _validatePHPSessionDir()
    {
        if (Util::isLinux()) {
            Log::step('Validating permissions of the PHP session directory...', true);

            $phpbinary = Util::getSettingFromPsaConf('CLIENT_PHP_BIN');
            $cmd = $phpbinary . " -r 'echo ini_get(\"session.save_path\");'";
            $path = Util::exec($cmd, $code);

            $cmd = 'su nobody -m -c "' . $phpbinary . ' -r \'@session_start();\' 2>&1"';
            $realResult = `$cmd`;

            Log::info("session.save_path = $path");

            if (!file_exists($path)) {
                // TODO no need to fail in this case, right?
                //Log::warning("No such directory {$path}");
                //Log::resultWarning();
                Log::info("No such directory '{$path}'");
                Log::resultOk();
                return;
            }

            $perms = (int)substr(decoct( fileperms($path) ), 2);
            Log::info('Permissions: '   . $perms);

            //:INFO: PHP on domains running via CGI/FastCGI can't use session by default http://kb.parallels.com/en/7056
            if (preg_match('/Permission\sdenied/', $realResult, $match)) {
            	$warn = "If a site uses default PHP settings and PHP is in CGI/FastCGI mode, site applications are unable to create user sessions. This is because the apps run on behalf of a subscription owner who does not have permissions to the directory which stores session files, " . $path . ". Please check http://kb.parallels.com/en/7056 for more details.";
            	Log::warning($warn);
            	Log::resultWarning();
            	return;
            }

            Log::resultOk();
        }
    }
}

class AutoinstallerKnownIssues
{
	function validate()
	{
		if (Util::isLinux()) {
			$this->_checkMixedPhpPackages();
		}
	}

	function _checkMixedPhpPackages()
	{
		if (PleskOS::isRedHatLike()) {
			Log::step("Checking that a mixed set of 'php' and 'php53' packages is not installed", true);

			$packages = PackageManager::listInstalled('php*', '/^php(53)?(-(common|devel|cli|mysql|sqlite2?|pdo|gd|imap|mbstring|xml))?$/');

			if ($packages === false) {
				Log::info("Failed to fetch php packages list from system package manager");
				return;
			}

			$hasPhp5 = $hasPhp53 = false;
			foreach ($packages as $package) {
				$name = $package['name'];
				$hasPhp5  |= ($name == 'php' || strpos($name, 'php-') === 0);
				$hasPhp53 |= (strpos($name, 'php53') === 0);
			}

			if ($hasPhp5 && $hasPhp53) {
				// We don't check for psa-php53?-configurator because a proper one will be installed depending on currently installed php(53)? package
				$warn = "You have a mixed set of 'php' and 'php53' packages installed. Installation or upgrade may fail or produce unexpected results. To resolve this issue run \"sed -i.bak -e '/^\s*skip-bdb\s*$/d' /etc/my.cnf ; yum update 'php*' 'mysql*'\".";
				Log::warning($warn);
				Log::resultWarning();
			} else {
				Log::resultOk();
			}
		}
	}
}

class Plesk10KnownIssues
{
    function validate()
    {
        if (!PleskInstallation::isInstalled()) {
            //:INFO: Plesk installation is not found. You will have no problems with upgrade, go on and install Plesk Panel 10
            return;
        }

        //:INFO: Validate known OS specific issues with recommendation to avoid bugs in Plesk
        if (Util::isLinux()
            && Util::isVz()
            && Util::getArch() == 'x86_64'
            && PleskOS::isSuse103()
        ) {
            $this->_diagnoseKavOnPlesk10x();
        }

        //:INFO: Prevent potential problem with E: Couldn't configure pre-depend plesk-core for psa-firewall, probably a dependency cycle.
        if (PleskVersion::is8x()
            && Util::isLinux()
            && Util::isVz()
            && PleskOS::isUbuntu804()
        ) {
            $this->_diagnoseDependCycleOfModules();
        }

        if (Util::isLinux()) {

			$this->_checkMainIP(); //:INFO: Checking for main IP address http://kb.parallels.com/en/112417
			$this->_checkCBMrelayURL(); //:INFO: Checking for hostname in Customer & Business Manager relay URL http://kb.parallels.com/en/111500
			$this->_checkMySQLDatabaseUserRoot(); //:INFO: Plesk user "root" for MySQL database servers have not access to phpMyAdmin http://kb.parallels.com/en/112779
			$this->_checkPAMConfigsInclusionConsistency();  //:INFO: Check that non-existent PAM services are not referenced by other services, i.e. that PAM configuration array is consistent http://kb.parallels.com/en/112944
			//:INFO: FIXED in 11.0.0 $this->_checkZendExtensionsLoadingOrder(); //:INFO:#100253 Wrong order of loading Zend extensions ionCube declaraion in php.ini can cause to Apache fail http://kb.parallels.com/en/1520
			$this->_checkDumpTmpDvalue(); //:INFO: #101168 If DUMP_TMP_D in /etc/psa/psa.conf contains extra space character at the end of string backup procedure will fails permanently http://kb.parallels.com/113474
			$this->_checkProftpdIPv6(); //:INFO: #94489 FTP service proftpd cannot be started by xinetd if IPv6 is disabled http://kb.parallels.com/en/113504
			//:INFO: FIXED in 11.0.0 $this->_checkModificationOfCustomizedDNSzones(); //:INFO: # http://kb.parallels.com/en/113725
			//:INFO: FIXED in 11.0.0 $this->_checkBindInitScriptPermission(); //:INFO: #105806 If there is no execute permission on named(bind) init file upgrade will fail
			$this->_checkMysqlclient15Release(); //:INFO: #105256 http://kb.parallels.com/en/113737
			$this->_checkNsRecordInDnsTemplate(); //:INFO: #94544  http://kb.parallels.com/en/113119
			$this->_checkMysqlOdbcConnectorVersion(); //:INFO: #102516 http://kb.parallels.com/en/113620
			$this->_checkSwCollectdIntervalSetting(); //:INFO: #105405 http://kb.parallels.com/en/113711
            $this->_checkApacheStatus();

			if (PLeskVersion::is10x()) {
				$this->_checkIpAddressReferenceForForwardingDomains(); //:INFO: #72945 Checking for IP address references in Plesk database http://kb.parallels.com/en/113475
			}

			if (PleskVersion::is10_0()) {
				$this->_oldBackupsRestoringWarningAfterUpgradeTo11x(); //:INFO: #58303  http://kb.parallels.com/en/114041
			}

			if (PleskVersion::is10_1_or_below()) {
				$this->_checkCustomizedCnameRecordsInDnsTemplate(); //:INFO: Customized CNAME records in server's DNS template could lead to misconfiguration of BIND http://kb.parallels.com/en/113118
			}

			if (PleskVersion::is10_2_or_above()) {
				$this->_checkSsoStartScriptsPriority(); //:INFO: Checking for conflicting of SSO start-up scripts http://kb.parallels.com/en/112666
				$this->_checkIpcollectionReference(); //:INFO: #72751 http://kb.parallels.com/en/113826
			}

			if (PleskVersion::is10_3_or_above()) {
				$this->_checkApsApplicationContext(); //:INFO: Broken contexts of the APS applications can lead to errors at building Apache web server configuration  http://kb.parallels.com/en/112815
			}

			if (!PleskVersion::is10_4_or_above()) {
				$this->_checkCustomPhpIniOnDomains(); //:INFO: Check for custom php.ini on domains http://kb.parallels.com/en/111697
			}

        	if (PleskOS::isDebLike()) {
        		$this->_checkSymLinkToOptPsa(); //:INFO: Check that symbolic link /usr/local/psa actually exists on Debian-like OSes http://kb.parallels.com/en/112214
        	}

        	if (Util::isVz()) {
        		$this->_checkUserBeancounters(); //:INFO: Checking that limits are not exceeded in /proc/user_beancounters http://kb.parallels.com/en/112522
            }

            if (PleskVersion::is10_4_or_above()) {
                $this->_checkCustomVhostSkeletonStatisticsSubdir();
            }

            if (PleskVersion::is10_3_or_above()) {
                $this->_checkApsTablesInnoDB();
            }
        }

		$this->_checkForCryptPasswords();
		$this->_checkAutoinstallerVersion(); //:INFO: Checking for old version of autoinstaller http://kb.parallels.com/en/112166
		$this->_checkMysqlServersTable(); //:INFO: Checking existing table mysql.servers
		$this->_checkUserHasSameEmailAsAdmin(); //:INFO: If user has the same address as the admin it should be changed to another http://kb.parallels.com/en/111985
		//:INFO: FIXED in 11.0.0       $this->_checkDefaultMySQLServerMode(); //:INFO:#66278,70525 Checking SQL mode of default client's MySQL server http://kb.parallels.com/en/112453
		//:INFO: FIXED in 10.4.4 MU#19 $this->_checkUserHasSameEmailAsEmailAccount(); //:INFO: Users with same e-mails as e-mail accounts will have problems with changing personal contact information http://kb.parallels.com/en/112032
		$this->_checkPleskTCPPorts(); //:INFO: Check the availability of Plesk Panel TCP ports http://kb.parallels.com/en/391
		$this->_checkFreeMemory(); //:INFO: Check for free memory http://kb.parallels.com/en/112522
		$this->_checkPanelAccessForLocalhost(); //:INFO: Upgrade of Customer & Business Manager failed in case of 127.0.0.1 is restricted for administrative access http://kb.parallels.com/en/113096
		//:INFO: FIXED in 11.0.0       $this->_checkCustomDNSrecordsEqualToExistedSubdomains(); //:INFO: Customized DNS records with host equal host of existing subdomain will lost after upgrade to Plesk version above 10.4.4 http://kb.parallels.com/en/113310
		$this->_checkForwardingURL(); //:INFO: Wrong GUI behavior if forwarding URL hasn't "http://" after upgrade to Plesk version above 10.4.4 http://kb.parallels.com/en/113359


		//:INFO:
		if (PleskVersion::is9x()
			|| PleskVersion::is8x()
		) {
			//:INFO: FIXED in 11.0.0 $this->_notificationChangePasswordForEmailAccount(); //:INFO: Notification about how to change password for the mail account after upgrade http://kb.parallels.com/en/9454
			if (Util::isLinux()) {
				$this->_checkJkWorkersFileDirective(); //:INFO: JkWorkersFile directive in Apache configuration can lead to failed Apache configs re-generation during and after upgrade procedure http://kb.parallels.com/en/113210
			}
			if (Util::isWindows()) {
				$this->_hMailServerWarningOnUpgradeTo10x(); //:INFO: Plesk 10 version does not support hMailserver http://kb.parallels.com/en/9609
				$this->_unsupportedFtpServersWarningOnUpgradeTo10x(); //:INFO: Plesk 10.2 version does not support Gene6 http://kb.parallels.com/en/111816
				$this->_unsupportedDNSServersWarningOnUpgradeTo10x(); //:INFO: Plesk 10.x version does not support SimpleDNS http://kb.parallels.com/en/112280
				$this->_mDaemonServerWarningOnUpgradeTo10x(); //:INFO: Plesk 10 version does not support MDaemon http://kb.parallels.com/en/112356

			}
		}

		if (PleskVersion::is10x()
			&& !PleskVersion::is10_2_or_above()
		) {
			$this->_checkCBMlicense(); //:INFO: Check for Customer and Business Manager license http://kb.parallels.com/en/111143
		}

		if (PleskVersion::is10_4()) {
			$this->_notificationSubDomainsHaveOwnDNSZoneSince104(); //:INFO: Notification about after upgrade all subdomains will have own DNS zone http://kb.parallels.com/en/112966
		}

		if (Util::isWindows()) {
			$this->_unknownISAPIfilters(); //:INFO: Checking for unknown ISAPI filters and show warning http://kb.parallels.com/en/111908
			$this->_checkMSVCR(); //:INFO: Just warning about possible issues related to Microsoft Visual C++ Redistributable Packages http://kb.parallels.com/en/111891
			$this->_checkConnectToClientMySQL(); //:INFO: Checking possibility to connect to client's MySQL server http://kb.parallels.com/en/111983
			$this->_checkIisFcgiDllVersion(); //:INFO: Check iisfcgi.dll file version http://kb.parallels.com/en/112606
			$this->_checkCDONTSmailrootFolder(); //:INFO: After upgrade Plesk change permissions on folder of Collaboration Data Objects (CDO) for NTS (CDONTS) to default, http://kb.parallels.com/en/111194
			$this->_checkWindowsAuthForPleskControlPanel(); //:INFO: Check windows authentication for PleskControlPanel web site http://kb.parallels.com/en/113253
			$this->_checkNullClientLogin(); //:INFO: #118963 http://kb.parallels.com/114835
			if (Util::isVz()) {
				$this->_checkDotNetFrameworkIssue(); //:INFO: Check that .net framework installed properly http://kb.parallels.com/en/111448
			}
			if (PleskVersion::is10x()) {
				$this->_checkSmarterMailOpenPorts(); //:INFO: #98549 Plesk doesn't bind Smartermail 8 ports on new IPs http://kb.parallels.com/en/113330
			}
		}
    }
	
	//:INFO: #118963 http://kb.parallels.com/114835
	function _checkNullClientLogin()
	{
		Log::step("Checking clients with empty login...", true);
		
		$mysql = PleskDb::getInstance();
    	$sql = "SELECT domains.id, domains.name, clients.login FROM domains LEFT JOIN clients ON clients.id=domains.cl_id WHERE clients.login is NULL";
    	$nullLogins = $mysql->fetchAll($sql);

    	if (!empty($nullLogins)) {
    		Log::warning('There are clients with empty login. This can break backup/migration. Please see http://kb.parallels.com/en/114835 for the solution.');
    		Log::resultWarning();
    		return;
    	}

    	Log::resultOk();
	}

    //:INFO: #58303 http://kb.parallels.com/en/114041
    function _oldBackupsRestoringWarningAfterUpgradeTo11x()
    {
    	Log::warning('Error messages can appear while restoring backups created in Panel 10.0.1. See http://kb.parallels.com/en/114041 for details.');
    	Log::resultWarning();
    }

    //:INFO: #105405 http://kb.parallels.com/en/113711
    function _checkSwCollectdIntervalSetting()
    {
    	Log::step("Checking the 'Interval' parameter in the sw-collectd configuration file...", true);

    	$collectd_config = '/etc/sw-collectd/collectd.conf';
    	if (file_exists($collectd_config)) {
    		if (!is_file($collectd_config) || !is_readable($collectd_config))
    		return;

    		$config_content = Util::readfileToArray($collectd_config);
    		if ($config_content) {
    			foreach ($config_content as $line) {
    				if (preg_match('/Interval\s*\d+$/', $line, $match)) {
    					if (preg_match('/Interval\s*10$/', $line, $match)) {
    						Log::warning('If you leave the default value of the "Interval" parameter in the ' . $collectd_config . ', sw-collectd may heavily load the system. Please see http://kb.parallels.com/en/113711 for details.');
    						Log::resultWarning();
    						return;
    					}
    					Log::resultOk();
    					return;
    				}
    			}
    			Log::warning('If you leave the default value of the "Interval" parameter in the ' . $collectd_config . ', sw-collectd may heavily load the system. Please see http://kb.parallels.com/en/113711 for details.');
    			Log::resultWarning();
    			return;
    		}
    	}
    }

    private function _checkApacheStatus()
    {
        Log::step("Checking Apache status...", true);

        $apacheCtl = file_exists('/usr/sbin/apache2ctl') ? '/usr/sbin/apache2ctl' : '/usr/sbin/apachectl';

        if (!is_executable($apacheCtl)) {
            return;
        }

        $resultCode = 0;
        Util::Exec("$apacheCtl -t 2>/dev/null", $resultCode);

        if (0 !== $resultCode) {
            Log::error("The Apache configuration is broken. Run '$apacheCtl -t' to see the detailed info.");
            Log::resultError();
            return;
        }

        Log::resultOk();
    }

    //:INFO: #94544  http://kb.parallels.com/en/113119
    function _checkNsRecordInDnsTemplate()
    {
    	Log::step("Checking NS type records in the Panel DNS template...", true);

    	$mysql = PleskDb::getInstance();
    	$sql = "SELECT 1 FROM dns_recs_t WHERE type='NS'";
    	$nsRecord = $mysql->fetchAll($sql);

    	if (empty($nsRecord)) {
    		Log::warning('There are no NS records in the Panel DNS template. This can break the BIND server configuration. Please see http://kb.parallels.com/en/113119 for the solution.');
    		Log::resultWarning();
    		return;
    	}

    	Log::resultOk();
    }

    //:INFO: #102516 http://kb.parallels.com/en/113620
    function _checkMysqlOdbcConnectorVersion()
    {
    	Log::step("Checking the version of MySQL ODBC package...", true);
    	if (PleskOS::isRedHatLike() || PleskOS::isSuseLike()) {
    		$package = 'mysql-connector-odbc';
    	} else {
    		$package = 'libmyodbc';
    	}

    	$version = Package::getVersion($package);

    	if ($version === false) {
    		return;
    	}

    	if (preg_match('/\d+\.\d+\.\d+/', $version, $match) && version_compare($match[0], '3.51.21', '<')) {
    		Log::warning('The installed version of ' . $package . ' is outdated. Please see http://kb.parallels.com/en/113620 for details.');
    		Log::resultWarning();
    		return;
    	}

    	Log::resultOk();
    }

    //:INFO: #72751  http://kb.parallels.com/en/113826
    function _checkIpcollectionReference()
    {
    	Log::step("Checking consistency of the IP addresses list in the Panel database...", true);

    	$mysql = PleskDb::getInstance();
    	$sql = "SELECT 1 FROM ip_pool, clients, IpAddressesCollections, domains, DomainServices, IP_Addresses WHERE DomainServices.ipCollectionId = IpAddressesCollections.ipCollectionId AND domains.id=DomainServices.dom_id AND clients.id=domains.cl_id AND ipAddressId NOT IN (select id from IP_Addresses) AND IP_Addresses.id = ip_pool.ip_address_id AND pool_id = ip_pool.id GROUP BY pool_id";
    	$brokenIps = $mysql->fetchAll($sql);
    	$sql = "select 1 from DomainServices, domains, clients, ip_pool where ipCollectionId not in (select IpAddressesCollections.ipCollectionId from IpAddressesCollections) and domains.id=DomainServices.dom_id and clients.id = domains.cl_id and ip_pool.id = clients.pool_id and DomainServices.type='web' group by ipCollectionId";
    	$brokenCollections = $mysql->fetchAll($sql);

    	if (!empty($brokenIps) || !empty($brokenCollections)) {
    		Log::warning('Some database entries related to Panel IP addresses are corrupted. Please see http://kb.parallels.com/en/113826 for the solution.');
    		Log::resultWarning();
    		return;
    	}

    	Log::resultOk();
    }

    //:INFO: #105256 http://kb.parallels.com/en/113737
    function _checkMysqlclient15Release()
    {
    	Log::step("Checking the version of the mysqlclient15 package...", true);
    	if (PleskOS::isRedHatLike()) {
    		$release = Package::getRelease('mysqlclient15');

    		if ($release === false) {
    			return;
    		}

    		if (preg_match('/1\.el5\.art/', $release)) {
    			Log::emergency('The installed version of mysqlclient15 is outdated. This may lead to upgrade fail. Please see http://kb.parallels.com/en/113737 for the solution');
    			Log::resultWarning();
    			return;
    		}
    	}
    	Log::resultOk();
    }

    //:INFO: #105806 If there is no execute permission on named(bind) init file upgrade will fail
    function _checkBindInitScriptPermission()
    {
    	Log::step("Checking permissions of the BIND initizalization script...", true);

    	$redhat = '/etc/init.d/named';
    	$debian = '/etc/init.d/bind9';
    	$suse = '/etc/init.d/named';
    	$bindInitFile = 'unknown';

    	if (PleskOS::isRedHatLike()) {
    		$bindInitFile = $redhat;
    	}
    	if (PleskOS::isDebLike()) {
    		$bindInitFile = $debian;
    	}
    	if (PleskOS::isSuseLike()) {
    		$bindInitFile = $suse;
    	}

    	$perms = Util::exec('ls -l ' . $bindInitFile, $code);

    	if (!preg_match('/^.+x.+\s/', $perms)
    		&& $code === 0) {
    		Log::emergency('The ' . $bindInitFile . ' does not have the execute premission. This may lead to upgrade fail. Please see http://kb.parallels.com/en/113733 for the solution.');
    		Log::resultWarning();
    		return;
    	}

    	Log::resultOk();
    }

    //:INFO: #101351 #101690 #103125 #104527 #104528 http://kb.parallels.com/en/113725
    function _checkModificationOfCustomizedDNSzones()
    {
    	Log::step("Checking for user-modified DNS records that will be changed during the upgrade...", true);

    	$mysql = PleskDb::getInstance();
    	if (PleskVersion::is10_2_or_above()) {
    		$ipv4 = "SELECT dns_zone.id, dns_zone.name, dns_recs.val, ip_address from dns_zone, dns_recs, DomainServices, IpAddressesCollections, IP_Addresses, domains where domains.dns_zone_id = dns_zone.id AND dns_zone.id = dns_recs.dns_zone_id AND dns_recs.type = 'A' AND dns_recs.host = concat(domains.name,'.') AND domains.id = DomainServices.dom_id AND DomainServices.type = 'web' AND DomainServices.ipCollectionId = IpAddressesCollections.ipCollectionId AND IpAddressesCollections.ipAddressId = IP_Addresses.id AND IP_Addresses.ip_address not like '%:%' AND dns_recs.val not like '%:%' AND IP_Addresses.ip_address <> dns_recs.val";
    		$ipv6 = "SELECT dns_zone.id, dns_zone.name, dns_recs.val, ip_address from dns_zone, dns_recs, DomainServices, IpAddressesCollections, IP_Addresses, domains where domains.dns_zone_id = dns_zone.id AND dns_zone.id = dns_recs.dns_zone_id AND dns_recs.type = 'A' AND dns_recs.host = concat(domains.name,'.') AND domains.id = DomainServices.dom_id AND DomainServices.type = 'web' AND DomainServices.ipCollectionId = IpAddressesCollections.ipCollectionId AND IpAddressesCollections.ipAddressId = IP_Addresses.id AND IP_Addresses.ip_address like '%:%' AND dns_recs.val like '%:%'  AND IP_Addresses.ip_address <> dns_recs.val";
    		$ipv4_zones = $mysql->fetchAll($ipv4);
    		$ipv6_zones = $mysql->fetchAll($ipv6);
    		$dns_zones = array_merge($ipv4_zones, $ipv6_zones);
    	} else {
    		$fwd = "SELECT dns_zone.id, dns_zone.name, dns_recs.val, ip_address AS hosts from dns_zone, dns_recs, forwarding, IP_Addresses, domains where domains.dns_zone_id = dns_zone.id AND dns_zone.id = dns_recs.dns_zone_id AND dns_recs.type = 'A' AND dns_recs.host = concat(domains.name,'.') AND domains.id = forwarding.dom_id AND forwarding.ip_address_id = IP_Addresses.id AND IP_Addresses.ip_address <> dns_recs.val";
    		$hst = "SELECT dns_zone.id, dns_zone.name, dns_recs.val, ip_address AS hosts from dns_zone, dns_recs, hosting, IP_Addresses, domains where domains.dns_zone_id = dns_zone.id AND dns_zone.id = dns_recs.dns_zone_id AND dns_recs.type = 'A' AND dns_recs.host = concat(domains.name,'.') AND domains.id = hosting.dom_id AND hosting.ip_address_id = IP_Addresses.id AND IP_Addresses.ip_address <> dns_recs.val";
    		$fwd_zones = $mysql->fetchAll($fwd);
    		$hst_zones = $mysql->fetchAll($hst);
    		$dns_zones = array_merge($fwd_zones, $hst_zones);
    	}

    	$warning = false;
    	foreach ($dns_zones as $zone) {
    		$subdomains = $mysql->fetchAll('SELECT subdomains.name FROM domains, subdomains WHERE subdomains.dom_id = domains.id AND domains.dns_zone_id=' . $zone['id']);
    		if (!empty($subdomains)) {
    			Log::info('The existing A and AAAA records in the DNS zone ' . $zone['name'] . ' will be modified or removed after the upgrade.');
    			$warning = true;
    		}
    	}

    	if ($warning) {
    		Log::warning('Some of the existing A or AAAA records in DNS zones will be modified or removed after the upgrade. Please see http://kb.parallels.com/en/113725 and ' . APP_PATH . '/plesk10_preupgrade_checker.log for details.');
    		Log::resultWarning();
    		return;
    	}

    	Log::resultOk();
    }

    //:INFO: Broken contexts of the APS applications can lead to errors at building Apache web server configuration http://kb.parallels.com/en/112815
    function _checkApsApplicationContext()
    {
    	Log::step("Checking installed APS applications...", true);
    	$mysql = PleskDb::getInstance();
    	$sql = "SELECT * FROM apsContexts WHERE (pleskType = 'hosting' OR pleskType = 'subdomain') AND subscriptionId = 0";
    	$brokenContexts = $mysql->fetchAll($sql);

    	if (!empty($brokenContexts)) {
    		Log::warning('Some database entries realted to the installed APS applications are corrupted. Please see http://kb.parallels.com/en/112815 for the solution.');
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: #98549 Plesk doesn't bind Smartermail 8 ports on new IPs http://kb.parallels.com/en/113330
    function _checkSmarterMailOpenPorts()
    {
    	Log::step("Checking SmarterMail open ports...", true);

    	if (Plesk10MailServer::CurrentWinMailServer() == 'smartermail') {
    		$ip_addresses = Util::getIPListOnWindows();

    		$mysql = PleskDb::getInstance();
    		$sql = "select ip_address from IP_Addresses";
    		$ip_addresses = $mysql->fetchAll($sql);

    		foreach ($ip_addresses as $ip) {
    			if (PleskValidator::validateIPv4($ip['ip_address'])) {
    				$fp = @fsockopen($ip['ip_address'], 25, $errno, $errstr, 1);
    			} elseif (PleskValidator::validateIPv6($ip['ip_address'])) {
    				$fp = @fsockopen('[' . $ip['ip_address'] . ']', 25, $errno, $errstr, 1);
    			} else {
    				Log::warning('The IP address is invalid: ' . $ip['ip_address']);
    				Log::resultWarning();
    				return;
    			}
    			if (!$fp) {
    				// $errno 110 means "timed out", 111 means "refused"
    				Log::info('Unable to connect to the SMTP port 25 on the IP address ' . $ip['ip_address'] . ': ' . $errstr);
    				$warning = true;
    			}
    		}
    		if ($warning) {
    			Log::warning('SmarterMail is unable to use some of the IP addresses because they are not associated with the SmarterMail ports. Please check http://kb.parallels.com/en/113330 for details.');
    			Log::resultWarning();
    			return;
    		}
    	}

    	Log::resultOk();
    }

    //:INFO: #94489 FTP service proftpd cannot be started by xinetd if IPv6 is disabled http://kb.parallels.com/en/113504
    function _checkProftpdIPv6()
    {
    	Log::step("Checking proftpd settings...", true);

    	$inet6 = '/proc/net/if_inet6';
    	if (!file_exists($inet6)) {
			$proftpd_config = '/etc/xinetd.d/ftp_psa';
    		if (!is_file($proftpd_config) || !is_readable($proftpd_config))
    			return null;

    		$config_content = Util::readfileToArray($proftpd_config);
    		if ($config_content) {
    			for ($i=0; $i<=count($config_content)-1; $i++) {
    				if (preg_match('/flags.+IPv6$/', $config_content[$i], $match)) {
    					Log::warning('The proftpd FTP service will fail to start in case the support for IPv6 is disabled on the server. Please check http://kb.parallels.com/en/113504 for details.');
    					Log::resultWarning();
    					return;
    				}
    			}
    		}
    	}
    	Log::resultOk();
    }

    //:INFO: #72945 Checking for IP address references in Plesk database http://kb.parallels.com/en/113475
    function _checkIpAddressReferenceForForwardingDomains()
    {
    	Log::step("Checking associations between domains and IP addresses...", true);
    	$mysql = PleskDb::getInstance();
    	if (PleskVersion::is10_2_or_above()) {
    		$sql = "SELECT * FROM IpAddressesCollections WHERE ipAddressId = 0";
    	} else {
    		$sql = "SELECT * FROM forwarding WHERE ip_address_id = 0";
    	}
    	$domains = $mysql->fetchAll($sql);

    	if (!empty($domains)) {
    		Log::warning('There is a number of domains which are not associated with any IP address. This may be caused by an error in the IP address database. Please check http://kb.parallels.com/en/113475 for details.');
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: #101168 If DUMP_TMP_D in /etc/psa/psa.conf contains extra space character at the end of string backup procedure will fails permanently http://kb.parallels.com/113474
    function _checkDumpTmpDvalue()
    {
    	Log::step("Checking the /etc/psa/psa.conf file for consistency...", true);

    	$file = '/etc/psa/psa.conf';
    	if (!is_file($file) || !is_readable($file))
    		return null;
    	$lines = file($file);
    	if ($lines === false)
    		return null;
    	foreach ($lines as $line) {
    		if (preg_match('/^DUMP_TMP_D\s.+\w $/', $line, $match_setting)) {
    			Log::warning('The DUMP_TMP_D variable in /etc/psa/psa.conf contains odd characters. This can cause backup tasks to fail on this server. Please check http://kb.parallels.com/113474 for details.');
    			Log::resultWarning();
    			return;
    		}
    	}
    	Log::resultOk();
    }

    //:INFO: Wrong order of loading Zend extensions ionCube declaraion in php.ini can cause to Apache fail http://kb.parallels.com/en/1520
    function _checkZendExtensionsLoadingOrder()
    {
    	Log::step("Checking for the Zend extension declaraion in php.ini...", true);

    	$phpini = Util::getPhpIni();
    	if ($phpini) {
    		foreach ($phpini as $line) {
    			if (preg_match('/^\s*zend_extension(_debug)?(_ts)?\s*=/i', $line, $match)) {
    				Log::warning('The server-wide php.ini file contains the declaration of the Zend extension. As a result, the Apache server may fail to start after the upgrade. Please check http://kb.parallels.com/en/1520 for more details.');
    				Log::resultWarning();
    				return;
    			}
    		}
    	}
    	Log::resultOk();
    }

    //:INFO: JkWorkersFile directive in Apache configuration can lead to failed Apache configs re-generation during and after upgrade procedure http://kb.parallels.com/en/113210
    function _checkJkWorkersFileDirective()
    {
    	Log::step("Checking for the JkWorkersFile directive in the Apache configuration...", true);

        $httpd_include_d = Util::getSettingFromPsaConf('HTTPD_INCLUDE_D') . '/';
    	if (empty($httpd_include_d)) {
    		$warn = 'Unable to open /etc/psa/psa.conf';
    		Log::warning($warn);
    		Log::resultWarning();
    		return;
    	}

    	$handle = @opendir($httpd_include_d);
    	if (!$handle) {
    		$warn = 'Unable to open dir ' . $httpd_include_d;
    		Log::warning($warn);
    		Log::resultWarning();
    		return;
    		}

    	$configs = array();
    	while ( false !== ($file = readdir($handle)) ) {
    		if (preg_match('/^\./', $file) || preg_match('/zz0.+/i', $file) || is_dir($httpd_include_d . $file))
    		continue;
    		$configs[] = $file;
    	}

    	closedir($handle);
    	$warning = false;

    	foreach ($configs as $config) {
    		$config_content = Util::readfileToArray($httpd_include_d . '/' . $config);
    		if ($config_content) {
    			for ($i=0; $i<=count($config_content)-1; $i++) {
    				if (preg_match('/^JkWorkersFile.+/', $config_content[$i], $match)) {
   						Log::warning('The Apache configuration file "' . $httpd_include_d . $config . '" contains the "' . $match[0] . '" directive.' );
    					$warning = true;
    				}
    			}
    		}
    	}

    	if ($warning) {
    		Log::warning('The JkWorkersFile directive may cause problems during the Apache reconfiguration after the upgrade. Please check http://kb.parallels.com/en/113210 for more details.');
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }



    //:INFO: Wrong GUI behavior if forwarding URL hasn't "http://" after upgrade to Plesk version above 10.4.4 http://kb.parallels.com/en/113359
    function _checkForwardingURL()
    {
    	Log::step("Checking domain URLs...", true);

    	$mysql = PleskDb::getInstance();
    	$sql = "SELECT htype, redirect FROM domains, forwarding WHERE domains.id=forwarding.dom_id AND forwarding.redirect NOT LIKE 'https://%' AND forwarding.redirect NOT LIKE 'http://%'";
    	$domains_with_wrong_url = $mysql->fetchAll($sql);

    	if (count($domains_with_wrong_url)>0) {
    		Log::warning('There are domains registered in Panel which URL does not have the http:// prefix. Such domains will not be shown on the Domains page. Check http://kb.parallels.com/en/113359 for more details.');
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Customized DNS records with host equal host of existing subdomain will lost after upgrade to Plesk version above 10.4.4
    function _checkCustomDNSrecordsEqualToExistedSubdomains()
    {
    	Log::step("Checking DNS records of subdomains...", true);

    	$mysql = PleskDb::getInstance();

    	if (PleskVersion::is10_2_or_above()) {
    		$sql = "SELECT DISTINCT dns_recs.dns_zone_id, dns_recs.type, host, val, opt FROM dns_recs, subdomains, domains WHERE host = concat(subdomains.name,'.', domains.name,'.') AND dns_recs.dns_zone_id IN ( SELECT dns_recs.dns_zone_id FROM dns_recs, subdomains, domains, hosting, IP_Addresses, DomainServices, IpAddressesCollections WHERE host = concat(subdomains.name,'.', domains.name,'.') AND subdomains.dom_id = domains.id AND domains.id = DomainServices.dom_id AND DomainServices.type = 'web' AND DomainServices.ipCollectionId = IpAddressesCollections.ipCollectionId AND IpAddressesCollections.ipAddressId = IP_Addresses.id AND IP_Addresses.ip_address <> dns_recs.val)";
    	} else {
    		$sql = "SELECT DISTINCT dns_recs.dns_zone_id, dns_recs.type, host, val, opt FROM dns_recs, subdomains, domains WHERE host = concat(subdomains.name,'.', domains.name,'.') AND dns_recs.dns_zone_id IN ( SELECT dns_recs.dns_zone_id FROM dns_recs, subdomains, domains, hosting, IP_Addresses WHERE host = concat(subdomains.name,'.', domains.name,'.') AND subdomains.dom_id = domains.id AND domains.id = hosting.dom_id AND hosting.ip_address_id = IP_Addresses.id AND IP_Addresses.ip_address <> dns_recs.val)";
    	}

    	if (Util::isWindows()) {
    		$dbprovider = Util::regPleskQuery('PLESK_DATABASE_PROVIDER_NAME');
    		if ($dbprovider <> 'MySQL') {
    			$sql = "SELECT DISTINCT dns_recs.dns_zone_id, dns_recs.type, host, val, opt FROM dns_recs, subdomains, domains WHERE host = (subdomains.name + '.' + domains.name + '.') AND dns_recs.dns_zone_id IN ( SELECT dns_recs.dns_zone_id FROM dns_recs, subdomains, domains, hosting, IP_Addresses WHERE host = (subdomains.name + '.' + domains.name + '.') AND subdomains.dom_id = domains.id AND domains.id = hosting.dom_id AND hosting.ip_address_id = IP_Addresses.id AND IP_Addresses.ip_address <> dns_recs.val)";
    		}
    	}
    	$problem_dns_records = $mysql->fetchAll($sql);

    	if (count($problem_dns_records)>0) {
    		Log::warning('There is a number of DNS records for the subdomains that you manually added to domain DNS zones. If you upgrade to Panel 10.4.4, these records will be lost. Check http://kb.parallels.com/en/113310 for more details.');
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Check windows authentication for PleskControlPanel web site http://kb.parallels.com/en/113253
    function _checkWindowsAuthForPleskControlPanel()
    {
    	Log::step('Checking the authentication settings of the PleskControlPanel website in IIS...', true);

    	$PleskControlPanel = 'PleskControlPanel';
    	$cmd = 'wmic.exe /namespace:\\\\root\\MicrosoftIISv2 path IIsWebServerSetting where "ServerComment = \'' . $PleskControlPanel . '\'" get name /VALUE';
    	$output = Util::exec($cmd, $code);
    	if (preg_match_all('/Name=(.+)/', $output, $siteName)) {
    		$cmd = 'wmic.exe /namespace:\\\\root\\MicrosoftIISv2 path IIsWebVirtualDirSetting where "Name = \'' . $siteName[1][0] . '/ROOT\'" get AuthNTLM /VALUE';
    		$output = Util::exec($cmd, $code);
    		if (preg_match_all('/AuthNTLM=FALSE/', $output, $matches)) {
    			Log::warning('Windows authentication for the PleskControlPanel website in IIS is disabled. Check http://kb.parallels.com/en/113253 for more details.');
    			Log::resultWarning();
    			return;
    		}

    	}
    	Log::resultOk();
    }

    //:INFO: Notification about after upgrade all subdomains will have own DNS zone http://kb.parallels.com/en/112966
    function _notificationSubDomainsHaveOwnDNSZoneSince104()
    {
    	Log::step('Checking for subdomains...', true);
    	$mysql = PleskDb::getInstance();
    	$sql = "select val from misc where param='subdomain_own_zones'";
    	$subdomain_own_zones = $mysql->fetchOne($sql);

    	if ($subdomain_own_zones == "true") {
    		Log::warning('Since Panel 10.4, all subdomains have their own DNS zone. Check http://kb.parallels.com/en/112966 for more details.');
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Customized CNAME records in server's DNS template could lead to misconfiguration of BIND http://kb.parallels.com/en/113118
    function _checkCustomizedCnameRecordsInDnsTemplate()
    {
    	Log::step("Checking for CNAME records added to the initial Panel DNS template...", true);

    	$mysql = PleskDb::getInstance();
    	$sql = "select * from dns_recs_t where type='CNAME' and host in ('<domain>.','ns.<domain>.','mail.<domain>.','ipv4.<domain>.','ipv6.<domain>.','webmail.<domain>.')";
    	$records = $mysql->fetchOne($sql);
    	if (!empty($records)) {
    		Log::warning("There are CNAME records that were added to the initial Panel DNS template. These records may cause incorrect BIND operation after upgrade. Please check http://kb.parallels.com/en/113118 for more details.");
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Upgrade of Customer & Business Manager failed in case of 127.0.0.1 is restricted for administrative access http://kb.parallels.com/en/113096
    function _checkPanelAccessForLocalhost()
    {
    	Log::step('Checking for restriction policy...', true);

    	$mysql = PleskDb::getInstance();
    	$sql = "select val from cl_param where param='ppb-url'";
    	$url = $mysql->fetchOne($sql);
    	if (!empty($url)) {
    		$sql = "select val from misc where param='access_policy'";
    		$policy = $mysql->fetchOne($sql);
    		$sql = "select netaddr from misc m,cp_access c where m.param='access_policy' and m.val='allow' and c.netaddr='127.0.0.1' and c.type='allow';";
    		$allow = $mysql->fetchOne($sql);
    		$sql = "select netaddr from misc m,cp_access c where m.param='access_policy' and m.val='deny' and c.netaddr='127.0.0.1' and c.type='deny';";
    		$deny = $mysql->fetchOne($sql);

    		if (!empty($allow)
    			|| (empty($deny) && $policy == 'deny')) {
    			Log::warning('The IP address 127.0.0.1 is restricted for administrative access. Upgrade of the Customer & Business Manager component will be impossible. Please check http://kb.parallels.com/en/113096 for more details.');
    			Log::resultWarning();
    			return;
    		}
    	}
    	Log::resultOk();
    }

    //:INFO: Checking that limits are not exceeded in /proc/user_beancounters
    function _checkUserBeancounters()
    {
    	Log::step("Checking that limits are not exceeded in /proc/user_beancounters ...", true);
    	$warning = false;
    	$user_beancounters = Util::readfileToArray('/proc/user_beancounters');
    	if ($user_beancounters) {
    		for ($i=2; $i<=count($user_beancounters)-1; $i++) {
    			if (preg_match('/\d{1,}$/', $user_beancounters[$i], $match)
    			&& $match[0]>0) {
    				if (preg_match('/^.+?:?.+?\b(\w+)\b/', $user_beancounters[$i], $limit_name)) {
    					Log::warning('Virtuozzo Container limit "' . trim($limit_name[1]) . '" was exceeded ' . $match[0] . ' times.');
    				}
    				$warning = true;
    			}
    		}
    	}

    	if ($warning) {
    		Log::warning('Limits set by Parallels Virtuozzo Container are exceeded. Please, check http://kb.parallels.com/en/112522 for more details.');
    		Log::resultWarning();
    		return;
    	}

    	Log::resultOk();
    }

    //:INFO: Checking for available free memory for the upgrade procedure http://kb.parallels.com/en/112522
    function _checkFreeMemory()
    {
    	Log::step('Checking for available free memory for the upgrade procedure ...', true);

    	$freeMem = Util::GetFreeSystemMemory();
    	if (!empty($freeMem)
    		&& $freeMem < 200000) {
    		Log::warning('Not enough memory to perform the upgrade: You should have at least 200 megabytes free. The current amount of free memory is: ' . $freeMem . ' Kb');
    		Log::resultWarning();
    	}
    	Log::resultOk();
    }

    //:INFO: Check for Customer and Business Manager property in license key  http://kb.parallels.com/en/111143
    function _checkCBMlicense()
    {
    	Log::step('Checking if the license key includes support for Customer and Business Manager ...', true);

    	$mysql = PleskDb::getInstance();
    	$sql = "select val from cl_param where param='ppb-url'";
    	$url = $mysql->fetchOne($sql);
    	$warning = false;
    	if (!empty($url)) {
    		if (Util::isLinux()) {
    			$license_folder = '/etc/sw/keys/keys/';
    		} else {
    			$license_folder = Util::getPleskRootPath() . 'admin\\repository\\keys\\';
    		}
    		$license_files = scandir($license_folder);
    		for ($i = 2; $i <= count($license_files) - 1; $i++) {
    			$file = file_get_contents($license_folder . $license_files[$i]);

    			if (preg_match('/modernbill.+\>(.+)\<.+modernbill/', $file, $accounts)) {
					if ($accounts[1] > 0) {
						Log::resultOk();
						return;
					}
    			}
    		}

    		Log::warning('If you had not purchased the Customer and Business Manager License you can not use it after the upgrade. Check the article http://kb.parallels.com/en/111143 for more details.');
    		Log::resultWarning();
    	}


    }

    //:INFO: Check the availability of Plesk Panel TCP ports
    function _checkPleskTCPPorts()
    {
    	Log::step('Checking the availability of Plesk Panel TCP ports...', true);
    	$plesk_ports = array('8880' => 'Plesk Panel non-secure HTTP port', '8443' => 'Plesk Panel secure HTTPS port');


    	$mysql = PleskDb::getInstance();
    	$sql = "select ip_address from IP_Addresses";
    	$ip_addresses = $mysql->fetchAll($sql);
    	$warning = false;
    	if (count($ip_addresses)>0) {
    		if (Util::isLinux()) {
    			$ipv4 = Util::getIPv4ListOnLinux();
    			$ipv6 = Util::getIPv6ListOnLinux();
    			if ($ipv6) {
    				$ipsInSystem = array_merge($ipv4, $ipv6);
    			} else {
    				$ipsInSystem = $ipv4;
    			}
    		} else {
    			$ipsInSystem = Util::getIPListOnWindows();
    		}
    		foreach ($ip_addresses as $ip) {
    			foreach ($plesk_ports as $port => $description) {
    				if (PleskValidator::validateIPv4($ip['ip_address']) && in_array($ip['ip_address'], $ipsInSystem)) {
    					$fp = @fsockopen($ip['ip_address'], $port, $errno, $errstr, 1);
    				} elseif (PleskValidator::validateIPv6($ip['ip_address']) && in_array($ip['ip_address'], $ipsInSystem)) {
    					$fp = @fsockopen('[' . $ip['ip_address'] . ']', $port, $errno, $errstr, 1);
    				} else {
    					Log::warning('IP address registered in Plesk is invalid or broken: ' . $ip['ip_address']);
    					Log::resultWarning();
    					return;
    				}
    				if (!$fp) {
    					// $errno 110 means "timed out", 111 means "refused"
    					Log::info('Unable to connect to IP address ' . $ip['ip_address'] . ' on ' . $description . ' ' . $port . ': ' . $errstr);
    					$warning = true;
    				}
    			}
    		}
    	}
    	if ($warning) {
    		Log::warning('Unable to connect to some Plesk ports. Please see ' . APP_PATH . '/plesk10_preupgrade_checker.log for details. Find the full list of the required open ports at http://kb.parallels.com/en/391 ');
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }

	function _getPAMServiceIncludes($serviceFile)
	{
		// Get array of PAM services that are included from a given PAM configuration file.
		$lines = file($serviceFile);
		$includes = array();

		foreach ($lines as $line) {
			// Note: we do not support here line continuations and syntax variants for old unsupported systems.
			$line = trim( preg_replace('/#.*$/', '', $line) );
			if (empty($line))
				continue;

			// See PAM installation script source for info on possible syntax variants.
			$tokens = preg_split('/\s+/', $line);
			$ref = null;
			if ($tokens[0] == '@include') {
				$ref = $tokens[1];
			} elseif ($tokens[1] == 'include' || $tokens[1] == 'substack') {
				$ref = $tokens[2];
			}

			if (!empty($ref)) {
				$includes[] = $ref;
			}
		}

		return $includes;
	}

	//:INFO: Check that non-existent PAM services are not referenced by other services, i.e. that PAM configuration array is consistent http://kb.parallels.com/en/112944
	function _checkPAMConfigsInclusionConsistency()
	{
		Log::step('Checking PAM configuration array consistency...', true);

		$pamDir = "/etc/pam.d/";
		$handle = @opendir($pamDir);
		if (!$handle) {
			$warn = 'Unable to open the PAM configuration directory "' . $pamDir . '". Check http://kb.parallels.com/en/112944 for more details.';
			Log::warning($warn);
			Log::resultWarning();
			return;
		}

		$services = array();
		while ( false !== ($file = readdir($handle)) ) {
			if (preg_match('/^\./', $file) || preg_match('/readme/i', $file) || is_dir($pamDir . $file))
				continue;
			$services[] = $file;
		}

		closedir($handle);

		$allIncludes = array();
		foreach ($services as $service) {
			$includes = $this->_getPamServiceIncludes($pamDir . $service);
			$allIncludes = array_unique(array_merge($allIncludes, $includes));
		}

		$missingIncludes = array_diff($allIncludes, $services);

		if (!empty($missingIncludes)) {
			$warn  = 'The PAM configuration is in inconsistent state. ';
			$warn .= 'If you proceed with the installation, the required PAM modules will not be installed. This will cause problems during the authentication. ';
			$warn .= 'Some PAM services reference the following nonexistent services: ' . implode(', ', $missingIncludes) . '. ';
			$warn .= 'Check http://kb.parallels.com/en/112944 for more details.';

			Log::warning($warn);
			Log::resultWarning();
			return;
		}

		Log::resultOk();
	}

    //:INFO: Plesk user "root" for MySQL database servers have not access to phpMyAdmin http://kb.parallels.com/en/112779
    function _checkMySQLDatabaseUserRoot()
    {
    	Log::step('Checking existence of Plesk user "root" for MySQL database servers ...', true);

    	$psaroot = Util::getSettingFromPsaConf('PRODUCT_ROOT_D');
    	$phpMyAdminConfFile = $psaroot . '/admin/htdocs/domains/databases/phpMyAdmin/libraries/config.default.php';
    	if (file_exists($phpMyAdminConfFile)) {
    		$phpMyAdminConfFileContent = file_get_contents($phpMyAdminConfFile);
    		if (!preg_match("/\[\'AllowRoot\'\]\s*=\s*true\s*\;/", $phpMyAdminConfFileContent)) {
    			$mysql = PleskDb::getInstance();
    			$sql = "select login, data_bases.name as db_name, displayName as domain_name from db_users, data_bases, domains where db_users.db_id = data_bases.id and data_bases.dom_id = domains.id and data_bases.type = 'mysql' and login = 'root'";
    			$dbusers = $mysql->fetchAll($sql);

    			foreach ($dbusers as $user) {
    				Log::warning('The database user "' . $user['login'] . '"  (database "' . $user['db_name'] . '" at "' . $user['domain_name'] . '") has no access to phpMyAdmin. Please check http://kb.parallels.com/en/112779 for more details.');
    				Log::resultWarning();
    				return;
    			}
    		}
    	}

    	Log::resultOk();
    }

    //:INFO: After upgrade Plesk change permissions on folder of Collaboration Data Objects (CDO) for NTS (CDONTS) to default, http://kb.parallels.com/en/111194
    function _checkCDONTSmailrootFolder()
    {
    	Log::step('Checking for CDONTS mailroot folder ...', true);
    	$mailroot = Util::getSystemDisk() . 'inetpub\mailroot\pickup';

    	if (is_dir($mailroot)) {
    		Log::warning('After upgrade you have to add write pemissions to psacln group on folder ' . $mailroot . '. Please, check http://kb.parallels.com/en/111194 for more details.');
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Checking for conflicting of SSO start-up scripts http://kb.parallels.com/en/112666
    function _checkSsoStartScriptsPriority()
    {
    	Log::step('Checking for SSO start-up script priority ...', true);
    	$sso_script = '/etc/sw-cp-server/applications.d/00-sso-cpserver.conf';
    	$sso_folder = '/etc/sso';

    	if (!file_exists($sso_script)
    	&& is_dir($sso_folder)) {
    		Log::warning('SSO start-up script has wrong execution priority. Please, check http://kb.parallels.com/en/112666 for more details.');
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Check iisfcgi.dll file version http://kb.parallels.com/en/112606
    function _checkIisFcgiDllVersion()
    {
    	Log::step("Checking the iisfcgi.dll file version ...", true);
    	$windir = Util::getSystemRoot();
		$iisfcgi = $windir . '\system32\inetsrv\iisfcgi.dll';
		if (file_exists($iisfcgi)) {
		  	$version = Util::getFileVersion($iisfcgi);
    		if (version_compare($version, '7.5.0', '>')
    			&& version_compare($version, '7.5.7600.16632', '<')) {
    			Log::warning('File iisfcgi.dll version ' . $version . ' is outdated. Please, check article http://kb.parallels.com/en/112606 for details');
    			return;
    		}
		}
		Log::resultOk();
    }

    //:INFO: Notification about how to change password for the mail account after upgrade http://kb.parallels.com/en/9454
    function _notificationChangePasswordForEmailAccount()
    {
    	Log::step("Checking for mailnames with access to Panel ...", true);

    	$mysql = PleskDb::getInstance();
    	$sql = "select count(mail_name) as num from mail m, Permissions p where p.value='true' and p.id=m.perm_id and p.permission='cp_access'";
    	$mailnames = $mysql->fetchOne($sql);
    	if ($mailnames > 0) {
    		$warn = 'You have ' . $mailnames . ' mailbox users that will be converted to subscription-level auxiliary users after upgrading to Panel 10. Learn how to change passwords for such users in http://kb.parallels.com/en/9454';
    		Log::warning($warn);
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Users with same e-mails as e-mail accounts will have problems with changing personal contact information http://kb.parallels.com/en/112032
    function _checkUserHasSameEmailAsEmailAccount()
    {
    	Log::step("Checking for users with same e-mail address as e-mail account ...", true);

    	$mysql = PleskDb::getInstance();
    	$sql = "select login, email from clients where email in (select concat(m.mail_name, '@', d.displayName) from domains d, mail m, Permissions p where m.perm_id=p.id and (p.permission='cp_access' and value='true'))";
    	if (Util::isWindows()) {
    		$dbprovider = Util::regPleskQuery('PLESK_DATABASE_PROVIDER_NAME');
    		if ($dbprovider <> 'MySQL') {
    			$sql = "select login, email from clients where email in (select (m.mail_name + '@' + d.displayName) from domains d, mail m, Permissions p where m.perm_id=p.id and (p.permission='cp_access' and value='true'))";
    		}
    	}
    	if (PleskVersion::is10x()) {
    		$sql = "select count(login) users, email from smb_users where email in (select email from smb_users group by email having count(email)>1) and email != '' group by email";
    	}

    	$problem_clients = $mysql->fetchAll($sql);

    	if (PleskVersion::is8x()
    		|| PleskVersion::is9x()) {
    		$sql = "select d.name domain_name, c.email domain_admin_email from domains d, dom_level_usrs dl, Cards c where c.id=dl.card_id and dl.dom_id=d.id and c.email in (select concat(m.mail_name, '@', d.displayName) from domains d, mail m, Permissions p where m.perm_id=p.id and (p.permission='cp_access' and value='true'))";
    		if (Util::isWindows()) {
    			$dbprovider = Util::regPleskQuery('PLESK_DATABASE_PROVIDER_NAME');
    			if ($dbprovider <> 'MySQL') {
    				$sql = "select d.name as domain_name, c.email as domain_admin_email from domains d, dom_level_usrs dl, Cards c where c.id=dl.card_id and dl.dom_id=d.id and c.email in (select (m.mail_name + '@' + d.displayName) from domains d, mail m, Permissions p where d.id=m.dom_id and m.perm_id=p.id and (p.permission='cp_access' and value='true'))";
    			}
    		}
    	}
    	$problem_domain_admins = $mysql->fetchAll($sql);

    	if (count($problem_clients)>0
    		|| count($problem_domain_admins)>0) {
    		foreach ($problem_clients as $client) {
    			if (PleskVersion::is10x()) {
    				$info = 'There are ' . $client['users'] . ' users with the same contact e-mail address ' . $client['email'];
    			} else {
    				$info = 'User ' . $client['login'] . ' has contact mail address as e-mail account ' . $client['email'];
    			}
    			Log::info($info);
    		}
    		foreach ($problem_domain_admins as $domain_admin) {
    			$info = 'Domain administrator of domain ' . $domain_admin['domain_name'] . ' has contact mail address as e-mail account ' . $domain_admin['domain_admin_email'];
    			Log::info($info);
    		}
    		if (PleskVersion::is10x()) {
    			Log::warning('There are a number of Panel users that have the same contact email. Please see the ' . APP_PATH . '/plesk10_preupgrade_checker.log for details.  You will not be able to change personal information (including passwords) of these users. Learn more at http://kb.parallels.com/en/112032.');
    		} else {
    			Log::warning('There are some users found with email matches mailboxes with permission to access Control Panel. See the ' . APP_PATH . '/plesk10_preupgrade_checker.log for details.  If a client\'s or domain administrator\'s e-mail address (in the profile) matches a mailbox in Plesk and the mailbox has the permission to access Control Panel, the upgrade procedure will create two auxiliarily user accounts (with the same e-mail) for such customers and Panel will not allow to change personal information (including passwords) for them. Please, check http://kb.parallels.com/en/112032 for more details.');
    		}

    		Log::resultWarning();
    		return;
    	}

    	Log::resultOk();
    }

    //:INFO: Checking for main IP address http://kb.parallels.com/en/112417
    function _checkMainIP()
    {
    	Log::step("Checking for main IP address ...", true);
    	$mysql = PleskDb::getInstance();
    	$sql = 'select * from IP_Addresses';
    	$ips = $mysql->fetchAll($sql);
    	$mainexists = false;
    	foreach ($ips as $ip) {
    		if (isset($ip['main'])) {
    			if ($ip['main'] == 'true') {
    				$mainexists = true;
    			}
    		} else {
    			Log::info('No field "main" in table IP_Addresses.');
    			Log::resultOk();
    			return;
    		}
    	}

    	if (!$mainexists) {
    		$warn = 'Unable to find "main" IP address in psa database. Please, check http://kb.parallels.com/en/112417 for more details.';
    		Log::warning($warn);
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Checking for hostname in Customer & Business Manager relay URL http://kb.parallels.com/en/111500
    function _checkCBMrelayURL()
    {
    	Log::step("Checking for hostname in Customer & Business Manager relay URL...", true);

    	$mysql = PleskDb::getInstance();
    	$sql = "select val from cl_param where param='ppb-url'";
    	$url = $mysql->fetchOne($sql);
    	if (preg_match("/\/\/(.*):/i", $url, $result)) {
    		if (!PleskValidator::isValidIp($result[1])) {
    			$ip = Util::resolveHostname($result[1]);
    			if (!Util::isFQDN($result[1])
    			|| !PleskValidator::isValidIp($ip)) {
    				$warn = 'If you see the 404 error when trying to access Customer & Business Manager, please see http://kb.parallels.com/en/111500 for the soultion.';
    				Log::warning($warn);
    				Log::resultWarning();
    				return;
    			}
    		}
    	}
    	Log::resultOk();
    }

    //:INFO:#66278,70525 Checking SQL mode of default client's MySQL server http://kb.parallels.com/en/112453
    function _checkDefaultMySQLServerMode()
    {
    	Log::step("Checking SQL mode of default client's MySQL server...", true);

    	$credentials = Util::getDefaultClientMySQLServerCredentials();
    	if (!empty($credentials)) {
    		$mysql = new DbClientMysql('localhost', $credentials['admin_login'], $credentials['admin_password'] , 'mysql', 3306);
    		if (!$mysql->hasErrors()) {
    			$sql = 'SELECT @@sql_mode';
    			$sqlmode = $mysql->fetchOne($sql);
    			if (preg_match("/STRICT_/i", $sqlmode, $match)) {
    				$warn = 'Please, switch off strict mode for MySQL server. Read carefully article http://kb.parallels.com/en/112453 for details.';
    				Log::warning($warn);
    				Log::resultWarning();
    				return;
    			}
    		}
    	}

    	Log::resultOk();
    }

    //:INFO: If user has the same address as the admin it should be changed to another http://kb.parallels.com/en/111985
    function _checkUserHasSameEmailAsAdmin()
    {
    	Log::step('Checking for users with the same e-mail address as the administrator...', true);
    	$adminEmail = Plesk10BusinessModel::_getAdminEmail();
    	if (!empty($adminEmail)) {
    		$db = PleskDb::getInstance();
    		if (PleskVersion::is10x_or_above()) {
    			$sql = "SELECT login, email FROM smb_users WHERE login<>'admin' and email='" . $adminEmail . "'";
    			$clients = $db->fetchAll($sql);
    		} else {
    			$sql = "SELECT login, email FROM clients WHERE login<>'admin' and email='" . $adminEmail . "'";
    			$clients = $db->fetchAll($sql);
    		}
    		if (!empty($clients)) {
    			foreach ($clients as $client) {
    				Log::info('The customer with the username ' . $client['login'] . ' has the same e-mail address as the Panel administrator: ' .  $client["email"]);
    			}
    			Log::warning('Some customers have e-mail addresses coinciding with the Panel administrator\'s e-mail address. Please see the ' . APP_PATH . '/plesk10_preupgrade_checker.log and check http://kb.parallels.com/en/111985 for details.');
    			Log::resultWarning();
    			return;
    		}
    	}
    	Log::resultOk();
    }

    //:INFO: Check that .net framework installed properly http://kb.parallels.com/en/111448
    function _checkDotNetFrameworkIssue()
    {
    	Log::step('Checking that .NET framework installed properly...', true);
    	$pleskCpProvider = Util::regPleskQuery('PLESKCP_PROVIDER_NAME', true);
    	if ($pleskCpProvider == 'iis') {
    		$cmd = '"' . Util::regPleskQuery('PRODUCT_ROOT_D', true) . 'admin\bin\websrvmng" --list-wdirs --vhost-name=pleskcontrolpanel';
    		$output = Util::exec($cmd, $code);
    		if (!preg_match("/wdirs/i", trim($output), $matches)) {
    			Log::warning('There is a problem with .NET framework.  Please, check http://kb.parallels.com/en/111448 for details.');
    			Log::resultWarning();
    			return;
    		}
    	}
    	Log::resultOk();

    }

    //:INFO: Check for custom php.ini on domains http://kb.parallels.com/en/111697
    function _checkCustomPhpIniOnDomains()
    {
    	Log::step('Checking for custom php.ini on domains...', true);

    	$domains = Plesk10BusinessModel::_getDomainsByHostingType('vrt_hst');
    	if (empty($domains)) {
    		Log::resultOk();
    		return;
    	}
    	$vhost = Util::getSettingFromPsaConf('HTTPD_VHOSTS_D');
    	if (empty($vhost)) {
    		$warn = 'Unable to read /etc/psa/psa.conf';
    		Log::warning($warn);
    		Log::resultWarning();
    		return;
    	}
    	$flag = false;
    	foreach ($domains as $domain) {
    		$filename = $vhost . '/' . $domain['name'] . '/conf/php.ini';
    		if (file_exists($filename)) {
    			$warn = 'Custom php.ini is used for domain ' . $domain['name'] . '.';
    			Log::warning($warn);
    			$flag = true;
    		}
    	}

    	if ($flag) {
    		$warn = 'After upgrade, Panel will not apply changes to certain website-level PHP settings due to they are predefined in /var/www/vhosts/DOMAINNAME/conf/php.ini. Please check http://kb.parallels.com/en/111697 for details.';
    		Log::warning($warn);
    		Log::resultWarning();
    		return;
    	}

    	Log::resultOk();
    }

    //:INFO: Plesk 10 version does not support Mdaemon mail server http://kb.parallels.com/en/112356
    function _mDaemonServerWarningOnUpgradeTo10x()
    {
    	Log::step('Checking for MDaemon mail server...', true);

    	if (Plesk10MailServer::CurrentWinMailServer() == 'mdaemon') {
    		$warn = 'Plesk 10 version does not support MDaemon. Please, check http://kb.parallels.com/en/112356 for more details.';
    		Log::warning($warn);
    		Log::resultWarning();

    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Checking existing table mysql.servers http://kb.parallels.com/en/112290
    function _checkMysqlServersTable()
    {
    	Log::step('Checking table "servers" in database "mysql"...', true);
    	$mySQLServerVersion = Util::getMySQLServerVersion();
    	if (version_compare($mySQLServerVersion, '5.1.0', '>=')) {
    		$credentials = Util::getDefaultClientMySQLServerCredentials();

    		if (!Util::isLinux() && preg_match('/AES-128-CBC/', $credentials['admin_password'])) {
    			Log::info('The administrator\'s password for the default MySQL server is encrypted.');
    			return;
    		}

    		$mysql = new DbClientMysql('localhost', $credentials['admin_login'], $credentials['admin_password'] , 'information_schema', 3306);
    		if (!$mysql->hasErrors()) {
    			$sql = 'SELECT * FROM information_schema.TABLES  WHERE TABLE_SCHEMA="mysql" and TABLE_NAME="servers"';
    			$servers = $mysql->fetchAll($sql);
    			if (empty($servers)) {
    				$warn = 'The table "servers" in the database "mysql" does not exist. Please check  http://kb.parallels.com/en/112290 for details.';
    				Log::warning($warn);
    				Log::resultWarning();
    				return;
    			}
    		}
    	}
    	Log::resultOk();
    }

    //:INFO: Plesk 10.x version does not support SimpleDNS http://kb.parallels.com/en/112280
    function _unsupportedDNSServersWarningOnUpgradeTo10x()
    {
    	Log::step('Detecting current DNS server...', true);
    	$dnsServer = PleskComponent::CurrentWinDNSServer();
    	if ($dnsServer == 'simpledns') {
    		$warn = 'Since Parallels Plesk Panel version 10 Simple DNS server is no longer supported. Please, check http://kb.parallels.com/en/112280 for more details.';
    		Log::warning($warn);
    		Log::resultWarning();

    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Check that there is symbolic link /usr/local/psa on /opt/psa on Debian-like Oses http://kb.parallels.com/en/112214
    function _checkSymLinkToOptPsa()
    {
    	Log::step('Checking symbolic link /usr/local/psa on /opt/psa...', true);

    	$link = @realpath('/usr/local/psa/version');
    	if (!preg_match('/\/opt\/psa\/version/', $link, $macthes)) {
    		$warn = "The symbolic link /usr/local/psa does not exist or has wrong destination. Read article http://kb.parallels.com/en/112214 to fix the issue.";
    		Log::warning($warn);
    		Log::resultWarning();
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Checking for old version of autoinstaller http://kb.parallels.com/en/112166
    function _checkAutoinstallerVersion()
    {
    	Log::step('Checking autoinstaller version...', true);

    	if (getenv('AUTOINSTALLER_VERSION')) {
    		Log::resultOk();
    		return;
    	}

    	if (Util::isWindows()) {
    		if	(PleskVersion::is9x()
    		|| PleskVersion::is8x()
    		) {
    			Log::resultOk();
    			return;
    		}
    	}

    	$installed_ai_version = Util::getAutoinstallerVersion();
    	if (version_compare($installed_ai_version, AI_VERSION, '<')) {
    		$warn = 'Your autoinstaller version '. $installed_ai_version .' is outdated. Please refer to article http://kb.parallels.com/en/112166 on how to obtain latest version of autoinstaller.';
    		Log::warning($warn);
    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Checking possibility to connect to client's MySQL server
    function _checkConnectToClientMySQL()
    {
    	Log::step('Checking connection to client MySQL server...', true);

    	$credentials = Util::getDefaultClientMySQLServerCredentials();

    	if ($credentials == NULL) {
    		$installedMySQLserver55 = Util::regQuery('\MySQL AB\MySQL Server 5.5', '/v version', true);
    		$installedMySQLserver51 = Util::regQuery('\MySQL AB\MySQL Server 5.1', '/v version', true);
    		$installedMySQLserver50 = Util::regQuery('\MySQL AB\MySQL Server 5.0', '/v version', true);

    		if ($installedMySQLserver55
    		 	|| $installedMySQLserver51
    		 	|| $installedMySQLserver50
    		) {
    			$warn = 'Default MySQL server is not registered in Parallels Plesk Panel. If you use custom MySQL instances you should register one at least according to article http://kb.parallels.com/en/111983.';
    			Log::warning($warn);
    			Log::resultWarning();
    			return;
    		}
    	}

    	if (preg_match('/AES-128-CBC/', $credentials['admin_password'])) {
    		Log::info('The administrator\'s password for the default MySQL server is encrypted.');
    		return;
    	}

    	$mysql = new DbClientMysql('localhost', $credentials['admin_login'], $credentials['admin_password'] , 'mysql', 3306);
    	if ($mysql->hasErrors()) {
            $warn = 'Unable to connect to the local default MySQL server. Please check  http://kb.parallels.com/en/111983 for details.';
            Log::warning($warn);
            Log::resultWarning();
            return;
        }
    	Log::info('Connected sucessfully', true);
    	$result = $mysql->query('CREATE DATABASE IF NOT EXISTS pre_upgrade_checker_test_db');

    	if (!$result) {
            $warn = 'User has not enough privileges. Please check http://kb.parallels.com/en/111983 for details.';
            Log::warning($warn);
            Log::resultWarning();
            return;
    	}
    	$result = $mysql->query('DROP DATABASE IF EXISTS pre_upgrade_checker_test_db');

    	if (!$result) {
            $warn = 'User has not enough privileges. Please check http://kb.parallels.com/en/111983 for details.';
            Log::warning($warn);
            Log::resultWarning();
            return;
    	}
    	Log::resultOk();

    }

    //:INFO: Checking for unknown ISAPI filters and show warning http://kb.parallels.com/en/111908
    function _unknownISAPIfilters()
    {
    	Log::step('Detecting installed ISAPI filters...', true);
    	if (Util::isUnknownISAPIfilters()) {
    		$warn = 'Please read carefully article http://kb.parallels.com/en/111908, for avoiding possible problems caused by unknown ISAPI filters.';
    		Log::warning($warn);
    		Log::resultWarning();

    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Warning about possible issues related to Microsoft Visual C++ Redistributable Packages ?http://kb.parallels.com/en/111891
    function _checkMSVCR()
    {
    	Log::step('Microsoft Visual C++ Redistributable Packages', true);
		$warn = 'Please read carefully article http://kb.parallels.com/en/111891, for avoiding possible problems caused by Microsoft Visual C++ Redistributable Packages.';
		Log::info($warn);

   		return;
    }

    //:INFO: Plesk 10.x version does not support Gene6 and Serv-U http://kb.parallels.com/en/111816, http://kb.parallels.com/en/111894
    function _unsupportedFtpServersWarningOnUpgradeTo10x()
    {
    	Log::step('Detecting current FTP server...', true);
    	$ftpserver = PleskComponent::CurrentWinFtpServer();
    	if ( $ftpserver == 'gene6'
    		|| $ftpserver == 'servu'
    	) {
    		$warn = 'Since Parallels Plesk Panel version 10 Gene6 and Serv-U FTP servers are no longer supported. Please, check http://kb.parallels.com/en/111816 and http://kb.parallels.com/en/111894 for more details.';
    		Log::warning($warn);
    		Log::resultWarning();

    		return;
    	}
    	Log::resultOk();
    }

    //:INFO: Plesk 10 version does not support hMailserver http://kb.parallels.com/en/9609
    function _hMailServerWarningOnUpgradeTo10x()
    {
    	Log::step('Detecting current mail server...', true);

    	if (Plesk10MailServer::CurrentWinMailServer() == 'hmailserver') {
    		$warn = 'Plesk 10 version does not support hMailserver. Please, check http://kb.parallels.com/en/9609 for more details.';
    		Log::warning($warn);
    		Log::resultWarning();

    		return;
    	}
    	Log::resultOk();
    }

    function _diagnoseKavOnPlesk10x()
    {
        Log::step('Detecting if antivirus is Kaspersky...', true);

        $pleskComponent = new PleskComponent();
        $isKavInstalled = $pleskComponent->isInstalledKav();

        Log::info('Kaspersky antivirus: ' . ($isKavInstalled ? ' installed' : ' not installed'));

        if (Util::isVz() && $isKavInstalled) {
            $warn = 'An old version of Kasperskiy antivirus is detected. ';
            $warn .= 'If you are upgrading to the Panel 10 using EZ templates, update the template of Kaspersky antivirus on hardware node to the latest version, and then upgrade the container.';
            Log::warning($warn);
            Log::resultWarning();

            return;
        }
        Log::resultOk();
    }

    function _diagnoseDependCycleOfModules()
    {
        //:INFO: Prevent potential problem with E: Couldn't configure pre-depend plesk-core for psa-firewall, probably a dependency cycle.
        Log::step('Detecting if Plesk modules are installed...', true);

        if (Util::isVz()
            && PleskModule::isInstalledWatchdog()
            && PleskModule::isInstalledVpn()
            && PleskModule::isInstalledFileServer()
            && PleskModule::isInstalledFirewall()
        ) {
            $warn = 'Plesk modules "watchdog, fileserver, firewall, vpn" were installed on container. ';
            $warn .= 'If you are upgrading to the Panel 10 using EZ templates, remove the modules, and then upgrade the container.';
            Log::warning($warn);
            Log::resultWarning();

            return;
        }
        Log::resultOk();
    }

    function _checkForCryptPasswords()
    {
        //:INFO: Prevent potential problem with E: Couldn't configure pre-depend plesk-core for psa-firewall, probably a dependency cycle.
        Log::step('Detecting if encrypted passwords are used...', true);

        $db = PleskDb::getInstance();
        $sql = "SELECT COUNT(*) AS cnt FROM accounts WHERE type='crypt' AND password not like '$%';";
        $r = $db->fetchAll($sql);

        if ($r[0]['cnt'] != '0')
        {
            $warn = 'There are ' . $r[0]['cnt'] . ' accounts with old algorithm encrypted passwords. Please refer to http://kb.parallels.com/en/112391 on how to change the passwords\' type to plain.';

            Log::warning($warn);
            Log::resultWarning();
            return;
        }
        Log::resultOk();
    }

    function _checkCustomVhostSkeletonStatisticsSubdir()
    {
        if (!(PleskVersion::is10_4_or_above() && version_compare(PleskVersion::getVersion(), '11.1.18', '<='))) {
            return;
        }

        // 'statistics' subdir in vhosts was removed starting from Plesk 11.1.18. It's customization will have no effect after upgrade.
        Log::step('Checking if the deprecated "statistics" subdirectory in virtual host templates can be removed ...', true);

        $unmodifiedSkelStatMd5sum = "3f5517860e8adfa4b05c9ea6268b38eb";
        $vhostsDir = Util::getSettingFromPsaConf('HTTPD_VHOSTS_D');
        $returnCode = 0;
        $currentSkelStatMd5sumList = Util::exec("find -L {$vhostsDir}/.skel/*/statistics -type f 2>/dev/null | xargs --no-run-if-empty md5sum | cut -d ' ' -f 1 | sort | uniq", $returnCode);

        if (empty($currentSkelStatMd5sumList)) {
            Log::info('The deprecated "statistics" subdirectory in virtual host template is already removed.');
            Log::resultOk();
        } elseif ($currentSkelStatMd5sumList == $unmodifiedSkelStatMd5sum) {
            Log::info('The "statistics" subdirectories of vhost templates do not contain custom content and will be safely removed during the upgrade.');
            Log::resultOk();
        } else {
            $warn = 'Some virtual host templates have customized content in the "statistics" subdirectories. In Panel 11.5 and later such customizations cannot be applied to domains because the "statistics" subdirectory is no longer used in the templates. ';
            $warn.= 'We recommend that you remove the "statistics" subdirectory from templates manually after the upgrade. ';
            $warn.= "You can find the \"statistics\" virtual hosts templates in {$vhostsDir}/.skel/*/statistics.";
            Log::warning($warn);
            Log::resultWarning();
        }
    }

    function _checkApsTablesInnoDB()
    {
        Log::step('Checking if apsc database tables have InnoDB engine...', true);

        $db = PleskDb::getInstance();
        $apsDatabase = $db->fetchOne("select val from misc where param = 'aps_database'");
        $sql = "SELECT TABLE_NAME FROM information_schema.TABLES where TABLE_SCHEMA = '$apsDatabase' and ENGINE = 'MyISAM'";
        $myISAMTables = $db->fetchAll($sql);
        if (!empty($myISAMTables)) {
            $myISAMTablesList = implode(', ', array_map('reset', $myISAMTables));
            $warn = 'The are tables in apsc database with MyISAM engine: ' . $myISAMTablesList . '. It would be updated to InnoDB engine.';
            Log::warning($warn);
            Log::resultWarning();
            return;
    	}
    	Log::resultOk();
    }
}

class PleskComponent
{
    function isInstalledKav()
    {
        return $this->_isInstalled('kav');
    }

    function _isInstalled($component)
    {
        //upgrade from 10.x version, use old database structure
		$sql = "SELECT * FROM ServiceNodeProperties WHERE name LIKE 'components.packages.%{$component}%'";

        $pleskDb = PleskDb::getInstance();
        $row = $pleskDb->fetchRow($sql);

        return (empty($row) ? false : true);
    }

    function CurrentWinFTPServer()
    {
    	if (Util::isWindows()) {
    		$currentFTPServer = Util::regQuery('\PLESK\PSA Config\Config\Packages\ftpserver', '/ve', true);
    		Log::info('Current FTP server is: ' . $currentFTPServer);
    		return $currentFTPServer;
    	}
    }

    function CurrentWinDNSServer()
    {
    	if (Util::isWindows()) {
    		$currentDNSServer = Util::regQuery('\PLESK\PSA Config\Config\Packages\dnsserver', '/ve', true);
    		Log::info('Current DNS server is: ' . $currentDNSServer);
    		return $currentDNSServer;
    	}
    }

    function getPackageVersion($package_name)
    {
    	if (Util::isWindows()) {
			$cmd = '"' . Util::getPleskRootPath() . 'admin\bin\packagemng" ' . $package_name;
    	} else {
    		if (PleskVersion::is10_4_or_above()) {
    			$cmd = '/usr/local/psa/admin/bin/packagemng -l';
    		} else {
    			$cmd = '/usr/local/psa/admin/bin/packagemng ' . $package_name . ' 2>/dev/null';
    		}
    	}
    	/* packagemng <package name> - returns "<package name>:<package version>" on Windows all versions and Unix till Plesk 10.4 versions
    	 * since Plesk 10.4 on linux packagemng -l should be used to return list of all packages
    	 * if <package name> doesn't exists OR not installed on Windows output will be "<package name>:"
    	 * if <package name> doesn't installed on Linux output will be "<package name>:not_installed"
    	 * if <package name> doesn't exists on Linux output will be "packagemng: Package <package name> is not found in Components table"
    	 */
    	$output = Util::exec($cmd, $code);
    	if (preg_match('/' . $package_name .  '\:(.+)/', $output, $version)) {
    		if ($version[1] <> 'not_installed') {
    			return $version[1];
    		}
    	}
    	return null;
    }
}

class PleskModule
{
    function isInstalledWatchdog()
    {
        return PleskModule::_isInstalled('watchdog');
    }

    function isInstalledFileServer()
    {
        return PleskModule::_isInstalled('fileserver');
    }

    function isInstalledFirewall()
    {
        return PleskModule::_isInstalled('firewall');
    }

    function isInstalledVpn()
    {
        return PleskModule::_isInstalled('vpn');
    }

    function _isInstalled($module)
    {
        $sql = "SELECT * FROM Modules WHERE name = '{$module}'";

        $pleskDb = PleskDb::getInstance();
        $row = $pleskDb->fetchRow($sql);

        return (empty($row) ? false : true);
    }
}

class PleskInstallation
{
    function validate()
    {
        if (!$this->isInstalled()) {
            Log::step('Plesk installation is not found. You will have no problems with upgrade, go on and install '.PleskVersion::getLatestPleskVersionAsString().' (http://www.parallels.com/products/plesk/)');
            return;
        }
        $this->_detectVersion();
    }

    function isInstalled()
    {
        $rootPath = Util::getPleskRootPath();
        if (empty($rootPath) || !file_exists($rootPath)) {
            //Log::fatal('Plesk is not installed. Please install Plesk Panel at first.');
            return false;
        }
        return true;
    }

    function _detectVersion()
    {
        Log::step('Installed Plesk version/build: ' . PleskVersion::getVersionAndBuild());

        $currentVersion = PleskVersion::getVersion();
        if (version_compare($currentVersion, PLESK_VERSION, 'eq')) {
            $err = 'You have already installed the latest version ' . PleskVersion::getLatestPleskVersionAsString() . '. ';
            $err .= 'Tool must be launched prior to upgrade to ' . PleskVersion::getLatestPleskVersionAsString() . ' for the purpose of getting a report on potential problems with the upgrade.';
            // TODO either introduce an option to suppress fatal error here, or always exit with 0 here.
            //Log::fatal($err);
            Log::info($err);
            exit(0);
        }

        if (!PleskVersion::is8x() && !PleskVersion::is9x() && !PleskVersion::is10x() && !PleskVersion::is11x()) {
            $err = 'Unable to find Plesk 8.x, Plesk 9.x, Plesk 10.x or Plesk 11.x. ';
            $err .= 'Tool must be launched prior to upgrade to ' . PleskVersion::getLatestPleskVersionAsString() . ' for the purpose of getting a report on potential problems with the upgrade.';
            Log::fatal($err);
        }
    }
}

class PleskVersion
{
    function is8x()
    {
        $version = PleskVersion::getVersion();
        return version_compare($version, '8.0.0', '>=') && version_compare($version, '9.0.0', '<');
    }

    function is9x()
    {
        $version = PleskVersion::getVersion();
        return version_compare($version, '9.0.0', '>=') && version_compare($version, '10.0.0', '<');
    }

    function is10x()
    {
        $version = PleskVersion::getVersion();
        return version_compare($version, '10.0.0', '>=') && version_compare($version, '11.0.0', '<');
    }

    function is11x()
    {
        $version = PleskVersion::getVersion();
        return version_compare($version, '11.0.0', '>=') && version_compare($version, '12.0.0', '<');
    }

    function is10_0()
    {
    	$version = PleskVersion::getVersion();
    	return version_compare($version, '10.0.0', '>=') && version_compare($version, '10.1.0', '<');
    }

    function is10x_or_above()
    {
    	$version = PleskVersion::getVersion();
    	return version_compare($version, '10.0.0', '>=');
    }

    function is10_1_or_below()
    {
    	$version = PleskVersion::getVersion();
    	return version_compare($version, '10.1.1', '<=');
    }

    function is10_2_or_above()
    {
    	$version = PleskVersion::getVersion();
    	return version_compare($version, '10.2.0', '>=');
    }

    function is10_3_or_above()
    {
    	$version = PleskVersion::getVersion();
    	return version_compare($version, '10.3.0', '>=');
    }

    function is10_4()
    {
    	$version = PleskVersion::getVersion();
    	return version_compare($version, '10.4.0', '>=') && version_compare($version, '10.5.0', '<');
    }

    function is10_4_or_above()
    {
    	$version = PleskVersion::getVersion();
    	return version_compare($version, '10.4.0', '>=');
    }

    function getVersion()
    {
        $version = PleskVersion::getVersionAndBuild();
        if (!preg_match('/([0-9]+[.][0-9]+[.][0-9])/', $version, $macthes)) {
            Log::fatal("Incorrect Plesk version format. Current version: {$version}");
        }
        return $macthes[1];
    }

    function getVersionAndBuild()
    {
        $versionPath = Util::getPleskRootPath().'/version';
        if (!file_exists($versionPath)) {
            Log::fatal("Plesk version file is not exists $versionPath");
        }
        $version = file_get_contents($versionPath);
        $version = trim($version);
        return $version;
    }

    function getLatestPleskVersionAsString()
    {
        return 'Parallels Panel ' . PLESK_VERSION;
    }
}

class Log
{
    public function Log()
    {
        $this->_logFile = APP_PATH . '/plesk10_preupgrade_checker.log';
        @unlink($this->_logFile);
    }

    private static function _getInstance()
    {
        static $_instance = null;
        if (is_null($_instance)) {
            $_instance = new Log();
        }
        return $_instance;
    }

    public static function fatal($msg)
    {
        $log = Log::_getInstance();
        $log->_log($msg, 'FATAL_ERROR');
    }

    public static function error($msg)
    {
        $log = Log::_getInstance();
        $log->_log($msg, 'ERROR');
    }

    public static function warning($msg)
    {
        $log = Log::_getInstance();
        $log->_log($msg, 'WARNING');
    }

    public static function emergency($msg)
    {
    	$log = Log::_getInstance();
    	$log->_log($msg, 'EMERGENCY');
    }

    public static function step($msg, $useNumber=false)
    {
        static $step = 1;

        if ($useNumber) {
            $msg = "==> STEP " . $step . ": {$msg}";
            $step++;
        } else {
            $msg = "==> {$msg}";
        }

        $log = Log::_getInstance();
        $log->_log($msg, 'INFO', PHP_EOL);
    }

    public static function resultOk()
    {
        $msg = 'Result: OK';
        Log::info($msg);
    }

    public static function resultWarning()
    {
        $msg = 'Result: Warning';
        Log::info($msg);
    }

    public static function resultError()
    {
        $msg = 'Result: Error';
        Log::info($msg);
    }

    public static function info($msg)
    {
        $log = Log::_getInstance();
        $log->_log($msg, 'INFO');
    }

    public static function debug($msg)
    {
        $log = Log::_getInstance();
       	$log->_log($msg, 'DEBUG');
    }

    public static function dumpStatistics()
    {
        global $errors, $warnings;

        $str = 'Found errors: ' . $errors
            . '; Found Warnings: ' . $warnings
        ;
        echo PHP_EOL . $str . PHP_EOL . PHP_EOL;
    }

    private function _log($msg, $type, $newLine='')
    {
        global $errors, $warnings, $emergency;

		// TODO modern PHP (from 5.3) issues warning:
		//  PHP Warning:  date(): It is not safe to rely on the system's timezone settings. You are *required* to use the date.timezone setting
		//  or the date_default_timezone_set() function. In case you used any of those methods and you are still getting this warning, you most
		//  likely misspelled the timezone identifier. We selected 'America/New_York' for 'EDT/-4.0/DST' instead in
		//  panel_preupgrade_checker.php on line 1282
    	if (getenv('AUTOINSTALLER_VERSION')) {
            $log = $newLine . "{$type}: {$msg}" . PHP_EOL;
    	} else {
            $date = date('Y-m-d h:i:s');
            $log = $newLine . "[{$date}][{$type}] {$msg}" . PHP_EOL;
        }
		if ($type == 'EMERGENCY') {
			$emergency++;
			fwrite(STDERR, $log);
		} elseif ($type == 'ERROR' || $type == 'FATAL_ERROR') {
            $errors++;
            fwrite(STDERR, $log);
        } elseif ($type == 'WARNING') {
            $warnings++;
            fwrite(STDERR, $log);
        } elseif ($type == 'INFO') {
            //:INFO: Dump to output and write log to the file
            echo $log;
        } elseif ($type == 'DEBUG') {
            //:INFO: Write debug info to the file
        }

        Log::write($this->_logFile, $log);

        //:INFO: Terminate the process if have the fatal error
        if ($type == 'FATAL_ERROR') {
            exit(1);
        }
    }

    public static function write($file, $content, $mode='a+')
    {
        $fp = fopen($file, $mode);
        fwrite($fp, $content);
        fclose($fp);
    }
}

class PleskDb
{
    var $_db = null;

    function PleskDb($dbParams)
    {
        switch($dbParams['db_type']) {
            case 'mysql':
                $this->_db = new DbMysql(
                    $dbParams['host'], $dbParams['login'], $dbParams['passwd'], $dbParams['db'], $dbParams['port']
                );
                break;

            case 'jet':
                $this->_db = new DbJet($dbParams['db']);
                break;

            case 'mssql':
                $this->_db = new DbMsSql(
                    $dbParams['host'], $dbParams['login'], $dbParams['passwd'], $dbParams['db'], $dbParams['port']
                );
                break;

            default:
                Log::fatal("{$dbParams['db_type']} is not implemented yet");
                break;
        }
    }

    function getInstance()
    {
        global $options;
        static $_instance = array();

        $dbParams['db_type']= Util::getPleskDbType();
        $dbParams['db']     = Util::getPleskDbName();
        $dbParams['port']   = Util::getPleskDbPort();
        $dbParams['login']  = Util::getPleskDbLogin();
        $dbParams['passwd'] = $options->getDbPasswd();
        $dbParams['host']   = Util::getPleskDbHost();

        $dbId = md5(implode("\n", $dbParams));

		$_instance[$dbId] = new PleskDb($dbParams);

        return $_instance[$dbId];
    }

    function fetchOne($sql)
    {
        if (DEBUG) {
            Log::info($sql);
        }
        return $this->_db->fetchOne($sql);
    }

    function fetchRow($sql)
    {
        $res = $this->fetchAll($sql);
        if (is_array($res) && isset($res[0])) {
            return $res[0];
        }
        return array();
    }

    function fetchAll($sql)
    {
        if (DEBUG) {
            Log::info($sql);
        }
        return $this->_db->fetchAll($sql);
    }
}

class DbMysql
{
    var $_dbHandler = null;

    function DbMysql($host, $user, $passwd, $database, $port)
    {
        if ( extension_loaded('mysql') ) {
            $this->_dbHandler = @mysql_connect("{$host}:{$port}", $user, $passwd);
            if (!is_resource($this->_dbHandler)) {
                $mysqlError = mysql_error();
                if (stristr($mysqlError, 'access denied for user')) {
                    $errMsg = 'Given <password> is incorrect. ' . $mysqlError;
                } else {
                    $errMsg = 'Unable to connect database. The reason of problem: ' . $mysqlError . PHP_EOL;
                }
                $this->_logError($errMsg);
            }
            @mysql_select_db($database, $this->_dbHandler);
        } else if ( extension_loaded('mysqli') ) {

            $this->_dbHandler = @mysqli_connect($host, $user, $passwd, $database, $port);
            if (!$this->_dbHandler) {
                $mysqlError = mysqli_connect_error();
                if (stristr($mysqlError, 'access denied for user')) {
                    $errMsg = 'Given <password> is incorrect. ' . $mysqlError;
                } else {
                    $errMsg = 'Unable to connect database. The reason of problem: ' . $mysqlError . PHP_EOL;
                }
                $this->_logError($errMsg);
            }
        } else {
            Log::fatal("No MySQL extension is available");
        }
    }

    function fetchAll($sql)
    {
        if ( extension_loaded('mysql') ) {
            $res = mysql_query($sql, $this->_dbHandler);
            if (!is_resource($res)) {
                $this->_logError('Unable to execute query. Error: ' . mysql_error($this->_dbHandler));
            }
            $rowset = array();
            while ($row = mysql_fetch_assoc($res)) {
                $rowset[] = $row;
            }
            return $rowset;
        } else if ( extension_loaded('mysqli') ) {
            $res = $this->_dbHandler->query($sql);
            if ($res === false) {
                $this->_logError('Unable to execute query. Error: ' . mysqli_error($this->_dbHandler));
            }
            $rowset = array();
            while ($row = mysqli_fetch_assoc($res)) {
                $rowset[] = $row;
            }
            return $rowset;
        } else {
            Log::fatal("No MySQL extension is available");
        }
    }

    function fetchOne($sql)
    {
        if ( extension_loaded('mysql') ) {
            $res = mysql_query($sql, $this->_dbHandler);
            if (!is_resource($res)) {
                $this->_logError('Unable to execute query. Error: ' . mysql_error($this->_dbHandler));
            }
            $row = mysql_fetch_row($res);
            return $row[0];
        } else if ( extension_loaded('mysqli') ) {
            $res = $this->_dbHandler->query($sql);
            if ($res === false) {
                $this->_logError('Unable to execute query. Error: ' . mysqli_error($this->_dbHandler));
            }
            $row = mysqli_fetch_row($res);
            return $row[0];
        } else {
            Log::fatal("No MySQL extension is available");
        }
    }

    function query($sql)
    {
        if ( extension_loaded('mysql') ) {
            $res = mysql_query($sql, $this->_dbHandler);
            if ($res === false ) {
                $this->_logError('Unable to execute query. Error: ' . mysql_error($this->_dbHandler) );
            }
            return $res;
        } else if ( extension_loaded('mysqli') ) {
            $res = $this->_dbHandler->query($sql);
            if ($res === false ) {
                $this->_logError('Unable to execute query. Error: ' . mysqli_error($this->_dbHandler) );
            }
            return $res;
        } else {
            Log::fatal("No MySQL extension is available");
        }
    }

    function _logError($message)
    {
        $message = "[MYSQL ERROR] $message";
        Log::fatal($message);
    }
}

class DbClientMysql extends DbMysql
{
    var $errors = array();

    function _logError($message)
    {
        $message = "[MYSQL ERROR] $message";
        Log::warning($message);
        $this->errors[] = $message;
    }

    function hasErrors() {
        return count($this->errors) > 0;
    }
}

class DbJet
{
    var $_dbHandler = null;

    function DbJet($dbPath)
    {
        $dsn = "Provider='Microsoft.Jet.OLEDB.4.0';Data Source={$dbPath}";
        $this->_dbHandler = new COM("ADODB.Connection", NULL, CP_UTF8);
        if (!$this->_dbHandler) {
            $this->_logError('Unable to init ADODB.Connection');
        }

        $this->_dbHandler->open($dsn);
    }

    function fetchAll($sql)
    {
        $result_id = $this->_dbHandler->execute($sql);
        if (!$result_id) {
            $this->_logError('Unable to execute sql query ' . $sql);
        }
		if ($result_id->BOF && !$result_id->EOF) {
            $result_id->MoveFirst();
		}
		if ($result_id->EOF) {
		    return array();
		}

		$rowset = array();
		while(!$result_id->EOF) {
    		$row = array();
    		for ($i=0;$i<$result_id->Fields->count;$i++) {
                $field = $result_id->Fields($i);
                $row[$field->Name] = (string)$field->value;
    		}
    		$result_id->MoveNext();
    		$rowset[] = $row;
		}
		return $rowset;
    }

    function fetchOne($sql)
    {
        $result_id = $this->_dbHandler->execute($sql);
        if (!$result_id) {
            $this->_logError('Unable to execute sql query ' . $sql);
        }
		if ($result_id->BOF && !$result_id->EOF) {
            $result_id->MoveFirst();
		}
		if ($result_id->EOF) {
		    //Log::fatal('Unable to find row');
		    return null;
		}
        $field = $result_id->Fields(0);
        $result = $field->value;

        return (string)$result;
    }

    function _logError($message)
    {
        $message = "[JET ERROR] $message";
        Log::fatal($message);
    }
}

class DbMsSql extends DbJet
{
    function DbMsSql($host, $user, $passwd, $database, $port)
    {
        $dsn = "Provider=SQLOLEDB.1;Initial Catalog={$database};Data Source={$host}";
        $this->_dbHandler = new COM("ADODB.Connection", NULL, CP_UTF8);
        if (!$this->_dbHandler) {
            $this->_logError('Unable to init ADODB.Connection');
        }
        $this->_dbHandler->open($dsn, $user, $passwd);
    }

    function _logError($message)
    {
        $message = "[MSSQL ERROR] $message";
        Log::fatal($message);
    }
}

class Util
{
    function isWindows()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return true;
        }
        return false;
    }

    function isLinux()
    {
        return !Util::isWindows();
    }

    function isVz()
    {
        $vz = false;
        if (Util::isLinux()) {
            if (file_exists('/proc/vz/veredir')) {
                $vz = true;
            }
        } else {
            $reg = 'REG QUERY "HKLM\SOFTWARE\SWsoft\Virtuozzo" 2>nul';
            Util::exec($reg, $code);
            if ($code==0) {
                $vz = true;
            }
        }
        return $vz;
    }

    function getArch()
    {
        global $arch;
        if (!empty($arch))
            return $arch;

        $arch = 'i386';
        if (Util::isLinux()) {
            $cmd = 'uname -m';
            $x86_64 = 'x86_64';
            $output = Util::exec($cmd, $code);
            if (!empty($output) && stristr($output, $x86_64)) {
                $arch = 'x86_64';
            }
        } else {
            $cmd = 'systeminfo';
            $output = Util::exec($cmd, $code);
            if (preg_match('/System Type:[\s]+(.*)/', $output, $macthes) && stristr($macthes[1], '64')) {
                $arch = 'x86_64';
            }
        }
        return $arch;
    }

    function getHostname()
    {
        if (Util::isLinux()) {
            $cmd = 'hostname -f';
        } else {
            $cmd = 'hostname';
        }
        $hostname = Util::exec($cmd, $code);

        if (empty($hostname)) {
        	$err = 'Command: ' . $cmd . ' returns: ' . $hostname . "\n";
        	$err .= 'Hostname is not defined and configured. Unable to get hostname. Server should have properly configured hostname and it should be resolved locally.';
            Log::fatal($err);
        }

        return $hostname;
    }

    function isFQDN($string)
    {
    	$tld_list = array(
                'aero', 'asia', 'biz', 'cat', 'com', 'coop', 'edu', 'gov', 'info', 'int', 'jobs', 'mil', 'mobi', 'museum', 'name', 'net',
    			'org', 'pro', 'tel', 'travel', 'xxx', 'ac', 'ad', 'ae', 'af', 'ag', 'ai', 'al', 'am', 'an', 'ao', 'aq', 'ar', 'as', 'at',
    			'au', 'aw', 'ax', 'az', 'ba', 'bb', 'bd', 'be', 'bf', 'bg', 'bh', 'bi', 'bj', 'bm', 'bn', 'bo', 'br', 'bs', 'bt', 'bv',
    			'bw', 'by', 'bz', 'ca', 'cc', 'cd', 'cf', 'cg', 'ch', 'ci', 'ck', 'cl', 'cm', 'cn', 'co', 'cr', 'cs', 'cu', 'cv', 'cx',
    			'cy', 'cz', 'dd', 'de', 'dj', 'dk', 'dm', 'do', 'dz', 'ec', 'ee', 'eg', 'eh', 'er', 'es', 'et', 'eu', 'fi', 'fj', 'fk',
    			'fm', 'fo', 'fr', 'ga', 'gb', 'gd', 'ge', 'gf', 'gg', 'gh', 'gi', 'gl', 'gm', 'gn', 'gp', 'gq', 'gr', 'gs', 'gt', 'gu',
    			'gw', 'gy', 'hk', 'hm', 'hn', 'hr', 'ht', 'hu', 'id', 'ie', 'il', 'im', 'in', 'io', 'iq', 'ir', 'is', 'it', 'je', 'jm',
    			'jo', 'jp', 'ke', 'kg', 'kh', 'ki', 'km', 'kn', 'kp', 'kr', 'kw', 'ky', 'kz', 'la', 'lb', 'lc', 'li', 'lk', 'lr', 'ls',
    			'lt', 'lu', 'lv', 'ly', 'ma', 'mc', 'md', 'me', 'mg', 'mh', 'mk', 'ml', 'mm', 'mn', 'mo', 'mp', 'mq', 'mr', 'ms', 'mt',
    			'mu', 'mv', 'mw', 'mx', 'my', 'mz', 'na', 'nc', 'ne', 'nf', 'ng', 'ni', 'nl', 'no', 'np', 'nr', 'nu', 'nz', 'om', 'pa',
    			'pe', 'pf', 'pg', 'ph', 'pk', 'pl', 'pm', 'pn', 'pr', 'ps', 'pt', 'pw', 'py', 'qa', 're', 'ro', 'rs', 'ru', 'rw', 'sa',
    			'sb', 'sc', 'sd', 'se', 'sg', 'sh', 'si', 'sj', 'sk', 'sl', 'sm', 'sn', 'so', 'sr', 'ss', 'st', 'su', 'sv', 'sy', 'sz',
    			'tc', 'td', 'tf', 'tg', 'th', 'tj', 'tk', 'tl', 'tm', 'tn', 'to', 'tp', 'tr', 'tt', 'tv', 'tw', 'tz', 'ua', 'ug', 'uk',
    			'us', 'uy', 'uz', 'va', 'vc', 've', 'vg', 'vi', 'vn', 'vu', 'wf', 'ws', 'ye', 'yt', 'za', 'zm', 'zw' );

    	$label = '[a-zA-Z0-9\-]{1,62}\.';
    	$tld = '[\w]+';
    	if(preg_match( '/^(' . $label. ')+(' . $tld . ')$/', $string, $match ) && in_array( $match[2], $tld_list )) {
    		return TRUE;
    	} else {
    		return FALSE;
    	}

    }

    function resolveHostname($hostname)
    {
    	$dns_record = dns_get_record($hostname, DNS_A | DNS_AAAA);

    	if (isset($dns_record[0]['ip'])) {
    		return $dns_record[0]['ip'];
    	}
    	if (isset($dns_record[0]["ipv6"])) {
    		return $dns_record[0]['ipv6'];
    	}

    	return null;
    }

    function getIP()
    {
        $list = Util::getIPList();
        return $list[0]; //main IP
    }

    function getIPList($lo=false)
    {
        if (Util::isLinux()) {
            $ifconfig = Util::lookupCommand('ifconfig');
            $output = Util::exec("{$ifconfig} -a", $code);
            if (!preg_match_all('/inet addr:([0-9\.]+)/', $output, $matches)) {
                Log::fatal('Unable to get IP address');
            }
            $ipList = $matches[1];
            foreach ($ipList as $key => $ip) {
                if (!$lo && substr($ip, 0, 3) == '127') {
                    unset($ipList[$key]);
                    continue;
                }
                trim($ip);
            }
            $ipList = array_values($ipList);
        } else {
            $cmd = 'hostname';
            $hostname = Util::exec($cmd, $code);
            $ip = gethostbyname($hostname);
            $res = ($ip != $hostname) ? true : false;
            if (!$res) {
                Log::fatal('Unable to retrieve IP address');
            }
            $ipList = array(trim($ip));
        }
        return $ipList;
    }

    function getIPv6ListOnLinux()
    {
    	$ifconfig = Util::lookupCommand('ifconfig');
    	$output = Util::exec("{$ifconfig} -a", $code);
    	if (!preg_match_all('/inet6 addr: ?([^ ][^\/]+)/', $output, $matches)) {
    		return;
    	}
    	return $matches[1];
    }

    function getIPv4ListOnLinux()
    {
    	$ifconfig = Util::lookupCommand('ifconfig');
    	$output = Util::exec("{$ifconfig} -a", $code);
    	if (!preg_match_all('/inet addr: ?([^ ]+)/', $output, $matches)) {
    		Log::fatal('Unable to get IP address');
    	}
    	return $matches[1];
    }

    function getIPListOnWindows()
    {
    	$cmd = 'wmic.exe path win32_NetworkAdapterConfiguration get IPaddress';
    	$output = Util::exec($cmd, $code);
    	if (!preg_match_all('/"(.*?)"/', $output, $matches)) {
    		Log::fatal('Unable to get IP address');
    	}
    	return $matches[1];
    }

    function getPleskRootPath()
    {
        global $_pleskRootPath;
        if (empty($_pleskRootPath)) {
            if (Util::isLinux()) {
                if (PleskOS::isDebLike()) {
                    $_pleskRootPath = '/opt/psa';
                } else {
                    $_pleskRootPath = '/usr/local/psa';
                }
            }
            if (Util::isWindows()) {
                $_pleskRootPath = Util::regPleskQuery('PRODUCT_ROOT_D', true);
            }
        }
        return $_pleskRootPath;
    }

    function getPleskDbName()
    {
        $dbName = 'psa';
        if (Util::isWindows()) {
            $dbName = Util::regPleskQuery('mySQLDBName');
        }
        return $dbName;
    }

    function getPleskDbLogin()
    {
        $dbLogin = 'admin';
        if (Util::isWindows()) {
            $dbLogin = Util::regPleskQuery('PLESK_DATABASE_LOGIN');
        }
        return $dbLogin;
    }

    function getPleskDbType()
    {
        $dbType = 'mysql';
        if (Util::isWindows()) {
            $dbType = strtolower(Util::regPleskQuery('PLESK_DATABASE_PROVIDER_NAME'));
        }
        return $dbType;
    }

    function getPleskDbHost()
    {
    	$dbHost = 'localhost';
    	if (Util::isWindows()) {
    		$dbProvider = strtolower(Util::regPleskQuery('PLESK_DATABASE_PROVIDER_NAME'));
    		if ($dbProvider == 'mysql' || $dbProvider == 'mssql') {
    			$dbHost = Util::regPleskQuery('MySQL_DB_HOST');
    		}
    	}
    	return $dbHost;
    }

    function getPleskDbPort()
    {
        $dbPort = '3306';
        if (Util::isWindows()) {
            $dbPort = Util::regPleskQuery('MYSQL_PORT');
        }
        return $dbPort;
    }

    function regPleskQuery($key, $returnResult=false)
    {
        $arch = Util::getArch();
        if ($arch == 'x86_64') {
            $reg = 'REG QUERY "HKLM\SOFTWARE\Wow6432Node\Plesk\Psa Config\Config" /v '.$key;
        } else {
            $reg = 'REG QUERY "HKLM\SOFTWARE\Plesk\Psa Config\Config" /v '.$key;
        }
        $output = Util::exec($reg, $code);

        if ($returnResult && $code!=0) {
            return false;
        }

        if ($code!=0) {
            Log::info($reg);
            Log::info($output);
            Log::fatal("Unable to get '$key' from registry");
        }
        if (!preg_match("/\w+\s+REG_SZ\s+(.*)/i", trim($output), $matches)) {
            Log::fatal('Unable to macth registry value by key '.$key.'. Output: ' .  trim($output));
        }

        return $matches[1];
    }

    function regQuery($path, $key, $returnResult=false)
    {
    	$arch = Util::getArch();
    	if ($arch == 'x86_64') {
    		$reg = 'REG QUERY "HKLM\SOFTWARE\Wow6432Node' . $path .  '" '.$key;
    	} else {
    		$reg = 'REG QUERY "HKLM\SOFTWARE' . $path .  '" '.$key;
    	}
    	$output = Util::exec($reg, $code);

    	if ($returnResult && $code!=0) {
    		return false;
    	}

    	if ($code!=0) {
    		Log::info($reg);
    		Log::info($output);
    		Log::fatal("Unable to get '$key' from registry");
    	}
    	if (!preg_match("/\s+REG_SZ\s+(.*)/i", trim($output), $matches)) {
    		Log::fatal('Unable to match registry value by key '.$key.'. Output: ' .  trim($output));
    	}

    	return $matches[1];
    }

    function getAutoinstallerVersion()
    {
    	if (Util::isLinux()) {
    		$rootPath = Util::getPleskRootPath();
    		$cmd = $rootPath . '/admin/sbin/autoinstaller --version';
    		$output = Util::exec($cmd, $code);
    	} else {
    		$cmd = '"' . Util::regPleskQuery('PRODUCT_ROOT_D', true) . 'admin\bin\ai.exe" --version';
    		$output = Util::exec($cmd, $code);
    	}
    	if (!preg_match("/\d+\.\d+\.\d+/", trim($output), $matches)) {
    		Log::fatal('Unable to match autoinstaller version. Output: ' .  trim($output));
    	}
    	return $matches[0];
    }

    function lookupCommand($cmd, $path = '/bin:/usr/bin:/usr/local/bin:/usr/sbin:/sbin:/usr/local/sbin', $exit = true)
    {
        $dirs = explode(':', $path);
        foreach ($dirs as $dir) {
            $util = $dir . '/' . $cmd;
            if (is_executable($util)) {
                return $util;
            }
        }
        if ($exit) {
            Log::fatal("{$cmd}: command not found");
        }
    }

    function getSystemDisk()
    {
    	$cmd = 'echo %SYSTEMROOT%';
    	$output = Util::exec($cmd, $code);
    	return substr($output, 0, 3);
    }

    function getSystemRoot()
    {
    	$cmd = 'echo %SYSTEMROOT%';
    	$output = Util::exec($cmd, $code);
    	return $output;
    }

    function getFileVersion($file)
    {
    	$fso = new COM("Scripting.FileSystemObject");
    	$version = $fso->GetFileVersion($file);
    	$fso = null;
    	return $version;
    }

    function isUnknownISAPIfilters()
    {
        $isUnknownISAPI = false;
        $knownISAPI = array ("ASP\\.Net.*", "sitepreview", "COMPRESSION", "jakarta");

        foreach ($knownISAPI as &$value) {
            $value = strtoupper($value);
        }
        $cmd='cscript ' . Util::getSystemDisk() . 'inetpub\AdminScripts\adsutil.vbs  ENUM W3SVC/FILTERS';
        $output = Util::exec($cmd,  $code);

        if ($code!=0) {
            Log::info("Unable to get ISAPI filters. Error: " . $output);
            return false;
        }
        if (!preg_match_all('/FILTERS\/(.*)]/', trim($output), $matches)) {
            Log::info($output);
            Log::info("Unable to get ISAPI filters from output: " . $output);
            return false;
        }
        foreach ($matches[1] as $ISAPI) {
            $valid = false;
            foreach ($knownISAPI as $knownPattern) {
                if (preg_match("/$knownPattern/i", $ISAPI)) {
                    $valid = true;
                    break;
                }
            }
            if (! $valid ) {
                Log::warning("Unknown ISAPI filter detected in IIS: " . $ISAPI);
                $isUnknownISAPI = true;
            }
        }

        return $isUnknownISAPI;
    }

    function getMySQLServerVersion()
    {
    	$credentials = Util::getDefaultClientMySQLServerCredentials();

    	if (!Util::isLinux() && preg_match('/AES-128-CBC/', $credentials['admin_password'])) {
    		Log::info('The administrator\'s password for the default MySQL server is encrypted.');
    		return;
    	}

    	$mysql = new DbClientMysql('localhost', $credentials['admin_login'], $credentials['admin_password'] , 'information_schema', 3306);
    	if (!$mysql->hasErrors()) {
    		$sql = 'select version()';
    		$mySQLversion = $mysql->fetchOne($sql);
    		if (!preg_match("/(\d{1,})\.(\d{1,})\.(\d{1,})/", trim($mySQLversion), $matches)) {
    			Log::fatal('Unable to match MySQL server version.');
    		}
    		return $matches[0];
    	}
    }

    function getDefaultClientMySQLServerCredentials()
    {
    	$db = PleskDb::getInstance();
    	$sql = "SELECT DatabaseServers.admin_login, DatabaseServers.admin_password FROM DatabaseServers WHERE type='mysql' AND host='localhost'";
    	$clientDBServerCredentials = $db->fetchAll($sql);
    	if (Util::isLinux()) {
    		$clientDBServerCredentials[0]['admin_password'] = Util::retrieveAdminMySQLDbPassword();
    	}
     	return $clientDBServerCredentials[0];
    }

	function retrieveAdminMySQLDbPassword()
	{
		if (Util::isLinux())
			return trim( Util::readfile("/etc/psa/.psa.shadow") );
		else
			return null;
	}

    function exec($cmd, &$code)
    {
        if (!$cmd) {
            Log::info('Unable to execute a blank command. Please see ' . APP_PATH . '/plesk10_preupgrade_checker.log for details.');

            $debugBacktrace = "";
            foreach (debug_backtrace() as $i => $obj) {
                $debugBacktrace .= "#{$i} {$obj['file']}:{$obj['line']} {$obj['function']} ()\n";
            }
            Log::debug("Unable to execute a blank command. The stack trace:\n{$debugBacktrace}");
            $code = 1;
            return '';
        }
        exec($cmd, $output, $code);
        return trim(implode("\n", $output));
    }

	function readfile($file)
	{
		if (!is_file($file) || !is_readable($file))
			return null;
		$lines = file($file);
		if ($lines === false)
			return null;
		return trim(implode("\n", $lines));
	}

	function readfileToArray($file)
	{
		if (!is_file($file) || !is_readable($file))
			return null;
		$lines = file($file);
		if ($lines === false)
			return null;
		return $lines;
	}

	function getSettingFromPsaConf($setting)
	{
		$file = '/etc/psa/psa.conf';
		if (!is_file($file) || !is_readable($file))
			return null;
		$lines = file($file);
		if ($lines === false)
			return null;
		foreach ($lines as $line) {
			if (preg_match("/^{$setting}\s.*/", $line, $match_setting)) {
				if (preg_match("/[\s].*/i", $match_setting[0], $match_value)) {
					$value = trim($match_value[0]);
					return $value;
				}
			}
		}
		return null;
	}

	function GetFreeSystemMemory()
	{
		if (Util::isLinux()) {
			$cmd = 'cat /proc/meminfo';
			$output = Util::exec($cmd, $code);
			if (preg_match("/MemFree:.+?(\d+)/", $output, $MemFree)) {
				if (preg_match("/SwapFree:.+?(\d+)/", $output, $SwapFree)) {
					return $MemFree[1] + $SwapFree[1]; // returns value in Kb
				}
			}
		} else {
			$cmd = 'wmic.exe OS get FreePhysicalMemory';
			$output = Util::exec($cmd, $code);
			if (preg_match("/\d+/", $output, $FreePhysicalMemory)) {
				$cmd = 'wmic.exe PAGEFILE get AllocatedBaseSize';
				$output = Util::exec($cmd, $code);
				if (preg_match("/\d+/", $output, $SwapAllocatedBaseSize)) {
					$cmd = 'wmic.exe PAGEFILE get CurrentUsage';
					$output = Util::exec($cmd, $code);
					if (preg_match("/\d+/", $output, $SwapCurrentUsage)) {
						return $FreePhysicalMemory[0] + ($SwapAllocatedBaseSize[0] - $SwapCurrentUsage[0]) * 1000; // returns value in Kb
					}
				}
			}
		}
	}

	function getPhpIni()
	{
		if (Util::isLinux()) {
			// Debian/Ubuntu  /etc/php5/apache2/php.ini /etc/php5/conf.d/
			// SuSE  /etc/php5/apache2/php.ini /etc/php5/conf.d/
			// CentOS 4/5 /etc/php.ini /etc/php.d
			if (PleskOS::isRedHatLike()) {
				$phpini = Util::readfileToArray('/etc/php.ini');
			} else {
				$phpini = Util::readfileToArray('/etc/php5/apache2/php.ini');
			}
		}

		return $phpini;
	}
}

class PackageManager
{
	function buildListCmdLine($glob)
	{
		if (PleskOS::isRedHatLike() || PleskOS::isSuseLike()) {
			$cmd = "rpm -qa --queryformat '%{NAME} %{VERSION}-%{RELEASE} %{ARCH}\\n'";
		} elseif (PleskOS::isDebLike()) {
			$cmd = "dpkg-query --show --showformat '\${Package} \${Version} \${Architecture}\\n'";
		} else {
			return false;
		}

		if (!empty($glob)) {
			$cmd .= " '" . $glob . "'";
		}

		return $cmd;
	}

	/*
	 * Fetches a list of installed packages that match given criteria.
	 * string $glob - Glob (wildcard) pattern for coarse-grained packages selection from system package management backend. Empty $glob will fetch everything.
	 * string $regexp - Package name regular expression for a fine-grained filtering of the results.
	 * returns array of hashes with keys 'name', 'version' and 'arch', or false on error.
	 */
	function listInstalled($glob, $regexp = null)
	{
		$cmd = PackageManager::buildListCmdLine($glob);
        if (!$cmd) {
            return array();
        }

		$output = Util::exec($cmd, $code);
		if ($code != 0) {
			return false;
		}

		$packages = array();
		$lines = explode("\n", $output);
		foreach ($lines as $line) {
			@list($pkgName, $pkgVersion, $pkgArch) = explode(" ", $line);
			if (empty($pkgName) || empty($pkgVersion) || empty($pkgArch))
				continue;
			if (!empty($regexp) && !preg_match($regexp, $pkgName))
				continue;
			$packages[] = array(
				'name' => $pkgName,
				'version' => $pkgVersion,
				'arch' => $pkgArch
			);
		}

		return $packages;
	}

	function isInstalled($glob, $regexp = null)
	{
		$packages = PackageManager::listInstalled($glob, $regexp);
		return !empty($packages);
	}
}

class Package
{
	function getManager($field, $package)
	{
	    $redhat = 'rpm -q --queryformat \'%{' . $field . '}\n\' ' . $package;
    	$debian = 'dpkg-query --show --showformat=\'${' . $field . '}\n\' '. $package . ' 2> /dev/null';
    	$suse = 'rpm -q --queryformat \'%{' . $field . '}\n\' ' . $package;
    	$manager = false;

    	if (PleskOS::isRedHatLike()) {
    		$manager = $redhat;
    	} elseif (PleskOS::isDebLike()) {
    		$manager = $debian;
    	} elseif (PleskOS::isSuseLike()) {
    		$manager = $suse;
    	} else {
    		return false;
    	}

    	return $manager;
	}

	/* DPKG doesn't supports ${Release}
	 *
	 */

	function getRelease($package)
	{
		$release = false;

		$manager = Package::getManager('Release', $package);

		if (!$manager) {
			return false;
		}

		$release = Util::exec($manager, $code);
		if (!$code === 0) {
			return false;
		}
		return $release;
	}

	function getVersion($package)
	{
		$version = false;

		$manager = Package::getManager('Version', $package);

		if (!$manager) {
			return false;
		}

		$version = Util::exec($manager, $code);
		if (!$code === 0) {
			return false;
		}
		return $version;
	}

}

class PleskOS
{
    function isSuse103()
    {
        return PleskOS::_detectOS('suse', '10.3');
    }

    function isUbuntu804()
    {
        return PleskOS::_detectOS('ubuntu', '8.04');
    }

    function isDebLike()
    {
    	if (PleskOS::_detectOS('ubuntu', '.*')
    	|| PleskOS::_detectOS('debian', '.*')
    	) {
    		return true;
    	}
    	return false;
    }

    function isSuseLike()
    {
    	if (PleskOS::_detectOS('suse', '.*')) {
    		return true;
    	}
    	return false;
    }

    function isRedHatLike()
    {
		return (PleskOS::isRedHat() || PleskOS::isCentOS() || PleskOS::isCloudLinux());
    }


    function isRedHat()
    {
    	if (PleskOS::_detectOS('red\s*hat', '.*')) {
    		return true;
    	}
    	return false;
    }

	function isCloudLinux()
	{
		return PleskOS::_detectOS('CloudLinux', '.*');
	}

    function isCentOS()
    {
    	if (PleskOS::_detectOS('centos', '.*')) {
    		return true;
    	}
    	return false;
    }


    function _detectOS($name, $version)
    {
        foreach (array(PleskOs::catPsaVersion(), PleskOS::catEtcIssue()) as $output) {
            if (preg_match("/{$name}[\s]+$version/i", $output)) {
                return true;
            }
        }
        return false;
    }

    function catPsaVersion()
    {
        if (is_file('/usr/local/psa/version')) {
            $cmd = 'cat /usr/local/psa/version';
        } elseif (is_file('/opt/psa/version')) {
            $cmd = 'cat /opt/psa/version';
        } else {
            return '';
        }
        $output = Util::exec($cmd, $code);

        return $output;
    }

    function catEtcIssue()
    {
        $cmd = 'cat /etc/issue';
        $output = Util::exec($cmd, $code);

        return $output;
    }

    function detectSystem()
    {
        Log::step('Detect system configuration');

        Log::info('OS: ' . (Util::isLinux() ? PleskOS::catEtcIssue() : 'Windows'));
        Log::info('Arch: ' . Util::getArch());
    }
}

class PleskValidator
{
    function isValidIp($value)
    {
        if (!is_string($value)) {
            return false;
        }
        if (!PleskValidator::validateIPv4($value) && !PleskValidator::validateIPv6($value)) {
            return false;
        }
        return true;
    }

    function validateIPv4($value)
    {
        $ip2long = ip2long($value);
        if ($ip2long === false) {
            return false;
        }

        return $value == long2ip($ip2long);
    }

    function validateIPv6($value)
    {
        if (strlen($value) < 3) {
            return $value == '::';
        }

        if (strpos($value, '.')) {
            $lastcolon = strrpos($value, ':');
            if (!($lastcolon && PleskValidator::validateIPv4(substr($value, $lastcolon + 1)))) {
                return false;
            }

            $value = substr($value, 0, $lastcolon) . ':0:0';
        }

        if (strpos($value, '::') === false) {
            return preg_match('/\A(?:[a-f0-9]{1,4}:){7}[a-f0-9]{1,4}\z/i', $value);
        }

        $colonCount = substr_count($value, ':');
        if ($colonCount < 8) {
            return preg_match('/\A(?::|(?:[a-f0-9]{1,4}:)+):(?:(?:[a-f0-9]{1,4}:)*[a-f0-9]{1,4})?\z/i', $value);
        }

        // special case with ending or starting double colon
        if ($colonCount == 8) {
            return preg_match('/\A(?:::)?(?:[a-f0-9]{1,4}:){6}[a-f0-9]{1,4}(?:::)?\z/i', $value);
        }

        return false;
    }
}

class CheckRequirements
{
    function validate()
    {
        if (!PleskInstallation::isInstalled()) {
            //:INFO: skip chking mysql extension if plesk is not installed
            return;
        }

        $reqExts = array();
        foreach ($reqExts as $name) {
            $status = extension_loaded($name);
            if (!$status) {
                $this->_fail("PHP extension {$name} is not installed");
            }
        }
    }

    function _fail($errMsg)
    {
        echo '===Checking requirements===' . PHP_EOL;
        echo PHP_EOL . 'Error: ' . $errMsg . PHP_EOL;
        exit(1);
    }
}

class GetOpt
{
    var $_argv = null;
	var $_adminDbPasswd = null;

    function GetOpt()
    {
        $this->_argv = $_SERVER['argv'];
		if (empty($this->_argv[1]) && Util::isLinux())
			$this->_adminDbPasswd = Util::retrieveAdminMySQLDbPassword();
		else
			$this->_adminDbPasswd = $this->_argv[1];
    }

    function validate()
    {
        if (empty($this->_adminDbPasswd) && PleskInstallation::isInstalled()) {
            echo 'Please specify Plesk database password';
            $this->_helpUsage();
        }
    }

    function getDbPasswd()
    {
        return $this->_adminDbPasswd;
    }

    function _helpUsage()
    {
        echo PHP_EOL . "Usage: {$this->_argv[0]} <plesk_db_admin_password>" . PHP_EOL;
        exit(1);
    }
}

$emergency = 0;
$errors = $warnings = 0;

//:INFO: Validate options
$options = new GetOpt();
$options->validate();

//:INFO: Validate PHP requirements, need to make sure that PHP extensions are installed
$checkRequirements = new CheckRequirements();
$checkRequirements->validate();

//:INFO: Validate Plesk installation
$pleskInstallation = new PleskInstallation();
$pleskInstallation->validate();

//:INFO: Detect system
$pleskOs = new PleskOS();
$pleskOs->detectSystem();

//:INFO: Need to make sure that given db password is valid
if (PleskInstallation::isInstalled()) {
    Log::step('Validating the database password');
    $pleskDb = PleskDb::getInstance();
    Log::resultOk();
}

//:INFO: Dump script version
Log::step('Pre-Upgrade analyzer version: ' . PRE_UPGRADE_SCRIPT_VERSION);

// Check for possible Autoinstaller problems
$aiKnownIssues = new AutoinstallerKnownIssues();
$aiKnownIssues->validate();


//:INFO: Check potential problems you may encounter during transition to Plesk 10 model.
$pleskBusinessModel = new Plesk10BusinessModel();
$pleskBusinessModel->validate();

//:INFO: Validate Plesk requirements before installation/upgrade
$pleskRequirements = new Plesk10Requirements();
$pleskRequirements->validate();

//:INFO: Validate issues related to Mail system
$pleskMailServer = new Plesk10MailServer();
$pleskMailServer->validate();

//:INFO: Validate issues related to Skin
$pleskSkin = new Plesk10Skin();
$pleskSkin->validate();

//:INFO: Validate issues related to Permissions
$pleskPermissions = new Plesk10Permissions();
$pleskPermissions->validate();

//:INFO: Validate known OS specific issues with recommendation to avoid bugs in Plesk
$pleskKnownIssues = new Plesk10KnownIssues();
$pleskKnownIssues->validate();

Log::dumpStatistics();

if ($emergency > 0) {
	exit(2);
}

if ($errors > 0 || $warnings > 0) {
	exit(1);
}
// vim:set et ts=4 sts=4 sw=4:
