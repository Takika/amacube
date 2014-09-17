<?php

class ldap_driver extends amacube_driver
{
    private $rc;
    private $amacube;
    private $config;

    private $amavis_mappings = array(
        'virus_lover' => 'amavisVirusLover',
        'spam_lover' => 'amavisSpamLover',
        # 'unchecked_lover' => false,
        'banned_files_lover' => 'amavisBannedFilesLover',
        'bad_header_lover' => 'amavisBadHeaderLover',
        'bypass_virus_checks' => 'amavisBypassVirusChecks',
        'bypass_spam_checks' => 'amavisBypassSpamChecks',
        'bypass_banned_checks' => 'amavisBypassBannedChecks',
        'bypass_header_checks' => 'amavisBypassHeaderChecks',
        'spam_modifies_subj' => 'amavisSpamModifiesSubj',
        'spam_tag_level' => 'amavisSpamTagLevel',
        'spam_tag2_level' => 'amavisSpamTag2Level',
        # 'spam_tag3_level' => 12,
        'spam_kill_level' => 'amavisSpamKillLevel',
        'spam_dsn_cutoff_level' => 'amavisSpamDsnCutoffLevel',
        'spam_quarantine_cutoff_level' => 'amavisSpamQuarantineCutoffLevel',

        'virus_quarantine_to' => 'amavisVirusQuarantineTo',
        'spam_quarantine_to' => 'amavisSpamQuarantineTo',
        'banned_quarantine_to' => 'amavisBannedQuarantineTo',

        'whitelist_sender' => 'amavisWhitelistSender',
        'blacklist_sender' => 'amavisBlacklistSender',

        # 'unchecked_quarantine_to' => '',
        'bad_header_quarantine_to' => 'amavisBadHeaderQuarantineTo',
        # 'clean_quarantine_to' => '',
        # 'archive_quarantine_to' => '',
    );

    protected $ldap;

    public $initialized = false;

    public function __construct($amacube)
    {
        $this->amacube = $amacube;
        $this->rc      = rcmail::get_instance();

        $ldap_config = array(
            'hosts'           => array(
            ),
            'port'            => 389,
            'use_tls'         => false,
            'ldap_version'    => 3,             // using LDAPv3
            'auth_method'     => '',            // SASL authentication method (for proxy auth), e.g. DIGEST-MD5
            'attributes'      => array(
            ),                                  // List of attributes to read from the server
            'vlv'             => false,         // Enable Virtual List View to more efficiently fetch paginated data (if server supports it)
            'config_root_dn'  => 'cn=config',   // Root DN to read config (e.g. vlv indexes) from
            'numsub_filter'   => '(objectClass=organizationalUnit)',   // with VLV, we also use numSubOrdinates to query the total number of records. Set this filter to get all numSubOrdinates attributes for counting
            'sizelimit'       => '0',           // Enables you to limit the count of entries fetched. Setting this to 0 means no limit.
            'timelimit'       => '0',           // Sets the number of seconds how long is spend on the search. Setting this to 0 means no limit.
            'network_timeout' => 10,            // The timeout (in seconds) for connect + bind arrempts. This is only supported in PHP >= 5.3.0 with OpenLDAP 2.x
            'referrals'       => false,         // Sets the LDAP_OPT_REFERRALS option. Mostly used in multi-domain Active Directory setups
        );

        $this->config = array_merge($ldap_config, $this->rc->config->get('amacube_ldap_config', $ldap_config));
        $this->_ldap_connect();
        $this->get_policy_from_ldap();

        $verify = $this->verify_policy_array();
        if (isset($verify) && is_array($verify)) {
            // TODO: something is dead wrong, database settngs do not verify
            // FiXME: throw error
            error_log("AMACUBE: verification of database settings failed..." . implode(',', $verify));
        }
    }

    private function _ldap_connect()
    {
        if (!$this->initialized) {
            $this->ldap = new rcube_ldap_generic($this->config);
            $this->ldap->set_cache(null);
            if ($this->initialized = $this->ldap->connect()) {
                $dn = $this->rc->config->get('amacube_ldap_binddn');
                $pw = $this->rc->config->get('amacube_ldap_bindpw');
                $this->initialized = $this->ldap->bind($dn, $pw);
                $this->ldap->set_debug(true);
            }
        }

        return $this->initialized;
    }

    private function get_policy_from_ldap()
    {
        if (!$this->initialized) {
            return;
        }

        $base     = $this->rc->config->get('amacube_ldap_base');
        $userdata = $this->rc->user->data;

        if (strpos($userdata['username'], '@') === false) {
            $user = $userdata['alias'];
        } else {
            $user = $userdata['username'];
        }

        $filter   = sprintf("(&(objectClass=amavisAccount)(cn=%s))", $user);
        $attrs    = array_merge($this->config['attributes'], array_values($this->amavis_mappings));
        $results  = $this->ldap->search($base, $filter, 'sub', $attrs);

        if ($results->count() == 1) {
            $entries = $results->entries(true);
            foreach ($entries as $dn => $entry) {
                foreach ($this->policy_setting as $key => $value) {
                    if (array_key_exists($key, $this->amavis_mappings)) {
                        $ldap_key  = strtolower($this->amavis_mappings[$key]);
                        $new_value = array_key_exists($ldap_key, $entry) ? $entry[$ldap_key] : $this->policy_setting[$key];
                        $this->policy_setting[$key] = $this->get_value($key, $new_value);
                    }
                }

                $this->policy_pk = $dn;
                $this->user_pk   = $dn;
            }
        }
    }

    public function get_policy()
    {
        return $this->policy_setting;
    }

    // manually set amavis settings, either from config or from POST request
    public function set_policy($array)
    {
        // verify the array is correct
        $error = $this->verify_policy_array($array);
        if (!empty($error)) {
            return $error;
        }

        // and set write to instance variable
        $this->policy_setting = $array;
    }

    public function get_value($key, $value)
    {
        $retval = null;
        // the boolean settings are stored as Y/N or null in the database
        if (in_array($key, $this->boolean_settings)) {
            if (!empty($value) && ($value == 'Y' || $value == 'TRUE')) {
                $retval = true;
            } else {
                $retval = false;
            }
        } else {
            $retval = $value;
        }

        return $retval;

    }

    public function save()
    {
    }

    public function is_active($type)
    {
        $types = array(
            'virus',
            'spam',
            'banned',
            'header'
        );

        $ret = false;
        if (in_array($type, $types)) {
            $ret = !$this->policy_setting['bypass_' . $type . '_checks'];
        }

        return $ret;
    }

    public function is_delivery($type,$method)
    {
        if ($type == 'banned') {
            $lover = $type . '_files_lover';
        } else {
            $lover = $type . '_lover';
        }

        if ($method == 'deliver' && $this->policy_setting[$lover]) {
            return true;
        }

        if ($method == 'quarantine' && !$this->policy_setting[$lover] && $this->policy_setting[$type . '_quarantine_to']) {
            return true;
        }

        if ($method == 'discard' && !$this->policy_setting[$lover] && !$this->policy_setting[$type . '_quarantine_to']) {
            return true;
        }

        return false;
    }

    public function is_supported($setting)
    {
        return array_key_exists($setting, $this->amavis_mappings);
    }

}

?>
