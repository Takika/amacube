<?php

abstract class amacube_driver
{
    // USER SETTINGS
    public $user_pk; // primary key for the user record
    private $priority = 7; // we do not change the amavis default for that
    public $fullname; // Full Name of the user, for reference, Amavis does not use that

    // POLICY SETTINGS
    public $policy_pk; // primary key of the policy record
    public $policy_name; // Name of the policy, for reference, Amavis does not use that
    public $policy_setting = array(
        'virus_lover' => false,         // bool
        'spam_lover' => false,          // bool
        'unchecked_lover' => false,     // bool
        'banned_files_lover' => false,  // bool
        'bad_header_lover' => false,    // bool
        'bypass_virus_checks' => false, // bool
        'bypass_spam_checks' => false,  // bool
        'bypass_banned_checks' => false,// bool
        'bypass_header_checks' => false,// bool
        'spam_modifies_subj' => true,   // bool
        'spam_tag_level' => 3,          // float
        'spam_tag2_level' => 8,         // float
        'spam_tag3_level' => 999,       // float
        'spam_kill_level' => 999,       // float
        'spam_dsn_cutoff_level' => 10,  // float
        'spam_quarantine_cutoff_level' => 20, // float

        'virus_quarantine_to' => true,      // string 'sql:', but treated as boolean
        'spam_quarantine_to' => false,      // string 'sql:', but treated as boolean
        'banned_quarantine_to' => false,    // string 'sql:', but treated as boolean

        'whitelist_sender' => array(),
        'blacklist_sender' => array(),

        'unchecked_quarantine_to' => '',    // unused
        'bad_header_quarantine_to' => '',   // unused
        'clean_quarantine_to' => '',        // unused
        'archive_quarantine_to' => '',      // unused
    );

    // class variables(static), the same in all instances:
    protected $boolean_settings = array(
            'virus_lover',
            'spam_lover',
            'unchecked_lover',
            'banned_files_lover',
            'bad_header_lover',
            'bypass_virus_checks',
            'bypass_spam_checks',
            'bypass_banned_checks',
            'bypass_header_checks',
            'spam_modifies_subj',
    );

    /*
     * Map values from backend
     */
    abstract function get_value($key, $value);

    /*
     * Save the policy to the backend
     */
    abstract function save();

    // Convenience methods
    function is_active($type)
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

    function is_delivery($type, $method)
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

    abstract function is_supported($setting);

    // set the checkbox checked mark if user is a NOT spam or virus lover
    // (the checkbox marks ACTIVATION of the check, DEACTIVATION means user is a *_lover)
    public function is_check_activated_checkbox($type)
    {
        $ret = true;
        if ($type !== 'virus' && $type !== 'spam') {
            //FIXME throw error
            $ret = false;
        } elseif ($this->policy_setting[$type . '_lover']) {
            // true means unchecked activation...
            $ret = false;
        }

        return $ret;
    }

    // method to verify the policy settings are correct
    function verify_policy_array($array = null)
    {
        // store the errors
        $errors = array();

        // check this-setting if no array was handed in
        if (!isset($array) || !is_array($array) || count($array) == 0) {
            $array = $this->policy_setting;
        }

        // check the booleans:
        if (is_bool($array['virus_lover']) === false) {
            array_push($errors, 'virus_lover');
        }

        if (is_bool($array['spam_lover']) === false) {
            array_push($errors, 'spam_lover');
        }

        if (is_bool($array['unchecked_lover']) === false) {
            array_push($errors, 'unchecked_lover');
        }

        if (is_bool($array['banned_files_lover']) === false) {
            array_push($errors, 'banned_files_lover');
        }

        if (is_bool($array['bad_header_lover']) === false) {
            array_push($errors, 'bad_header_lover');
        }

        if (is_bool($array['bypass_virus_checks']) === false) {
            array_push($errors, 'bypass_virus_checks');
        }

        if (is_bool($array['bypass_spam_checks']) === false) {
            array_push($errors, 'bypass_spam_checks');
        }

        if (is_bool($array['bypass_banned_checks']) === false) {
            array_push($errors, 'bypass_banned_checks');
        }

        if (is_bool($array['bypass_header_checks']) === false) {
            array_push($errors, 'bypass_header_checks');
        }

        if (is_bool($array['spam_modifies_subj']) === false) {
            array_push($errors, 'spam_modifies_subj');
        }

        // check the floats:
        if (is_numeric($array['spam_tag_level']) === false) {
            array_push($errors, 'spam_tag_level:' . $array['spam_tag_level'] . "___" . gettype($array['spam_tag_level']));
        }

        if (is_numeric($array['spam_tag2_level']) === false) {
            array_push($errors, 'spam_tag2_level');
        }

        if (is_numeric($array['spam_tag3_level']) === false) {
            array_push($errors, 'spam_tag3_level');
        }

        if (is_numeric($array['spam_kill_level']) === false) {
            array_push($errors, 'spam_kill_level');
        }

        if (is_numeric($array['spam_dsn_cutoff_level']) === false) {
            array_push($errors, 'spam_dsn_cutoff_level');
        }

        if (is_numeric($array['spam_quarantine_cutoff_level']) === false) {
            array_push($errors, 'spam_quarantine_cutoff_level');
        }

        if (is_bool($array['virus_quarantine_to']) === false) {
            array_push($errors, 'virus_quarantine_to');
        }

        if (is_bool($array['spam_quarantine_to']) === false) {
            array_push($errors, 'spam_quarantine_to');
        }

        if (is_bool($array['banned_quarantine_to']) === false) {
            array_push($errors, 'banned_quarantine_to');
        }

        // make sure the array does not contain any other keys
        foreach ($array as $key => $value) {
            if (!array_key_exists($key, $this->policy_setting)) {
                // unkonwn key found
                array_push($errors, 'unknown:' . $key);
            }
        }

        // return if error found:
        if (!empty($errors)) {
            return $errors;
        }
    }
}

?>
