<?php

class database_driver extends amacube_driver
{
    private $rc;
    private $amacube;
    private $db_config;

    protected $db_conn;
    protected $user_email;

    public function __construct($amacube)
    {
        $this->amacube    = $amacube;
        $this->rc         = rcmail::get_instance();
        $this->db_config  = $this->rc->config->get('amacube_db_dsn');
        $this->user_email = $this->rc->user->data['username'];

        // Check for account catchall and adjust user_email accordingly
        if (isset($this->rc->amacube->catchall) && $this->rc->amacube->catchall == true) {
            $this->user_email = substr(strrchr($this->user_email, "@"), 0);
        }

        // Read config from DB
        $this->initialized = $this->read_from_db();
        // Verify policy config from database
        if ($this->initialized) {
            $this->verify_policy_array();
        }
    }

    private function init_db()
    {
        if (!$this->db_conn) {
            if (!class_exists('rcube_db')) {
                // Version: < 0.9
                $this->db_conn = new rcube_mdb2($this->db_config, '', true);
            } else {
                // Version: > 0.9
                $this->db_conn = rcube_db::factory($this->db_config, '', true);
            }
        }

        $this->db_conn->db_connect('w');
        // Error check
        if ($error = $this->db_conn->is_error()) {
            $this->rc->amacube->errors[] = 'db_connect_error';
            write_log('errors', 'AMACUBE: Database connect error: ' . $error);
            return false;
        }

        return true;
    }

    private function read_from_db()
    {
        if (!is_resource($this->db_conn)) {
            if (!$this->init_db()) {
                return false;
            }
        }

        // Get query for user and policy config
        $query = "SELECT users.id as user_id, users.priority, users.email, users.fullname, policy.*
            FROM users, policy
            WHERE users.policy_id = policy.id 
            AND users.email = ? ";

        $res = $this->db_conn->query($query, $this->user_email);
        // Error check
        if ($error = $this->db_conn->is_error()) {
            $this->rc->amacube->errors[] = 'db_query_error';
            write_log('errors', 'AMACUBE: Database query error: ' . $error);
        }

        // Get record for user and map policy config
        if ($res && ($res_array = $this->db_conn->fetch_assoc($res))) {
            foreach ($this->policy_setting as $key => $value) {
                $this->policy_setting[$key] = $this->map_from_db($key, $res_array[$key]);
            }

            $this->user_pk     = $res_array['user_id'];
            $this->priority    = $res_array['priority'];
            $this->fullname    = $res_array['fullname'];
            $this->policy_pk   = $res_array['id'];
            $this->policy_name = $res_array['policy_name'];
            return true;
        }

        return false;
    }

    public function get_value($key, $value)
    {
        return $value;
    }

    public function save()
    {
        return true;
    }

    public function is_supported($setting)
    {
        return true;
    }

    // Mapping function for internal representation -> database content
    private function map_to_db($key, $value)
    {
        $retval = null;
        // Map boolean settings to Y/N
        if (in_array($key, $this->boolean_settings)) {
            if ($value) {
                $retval = 'Y';
            } else {
                $retval = 'N';
            }
        } elseif (in_array($key, $this->tosql_settings)) {
            // Map tosql settings to sql:/null
            if ($value) {
                $retval = 'sql:';
            } else {
                $retval = null;
            }
        } else {
            // No mapping needed for other settings
            $retval = $value;
        }

        return $retval;
    }

    // Mapping function for internal representation <- database content
    private function map_from_db($key, $value)
    {
        $retval = null;
        // Map boolean settings from Y/N
        if (in_array($key, $this->boolean_settings)) {
            if (!empty($value) && $value == 'Y') {
                $retval = true;
            } else {
                $retval = false;
            }
        } elseif (in_array($key, $this->tosql_settings)) {
            // Map tosql settings from sql:/null
            if (!empty($value) && $value == 'sql:') {
                $retval = true;
            } else {
                $retval = false;
            }
        } else {
            // No mapping needed for other settings
            $retval = $value;
        }

        return $retval;
    }
}
?>
