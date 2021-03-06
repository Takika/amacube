<?php

class amacube extends rcube_plugin
{
    // All tasks excluding 'login' and 'logout'
    public $task = '?(?!login|logout).*';

    private	$rc;
    private	$amacube;

    function init()
    {
        $this->rc      = rcmail::get_instance();
        $this->amacube = new stdClass;

        // Load plugin config
        $this->load_config();

        // Amacube storage on rcmail instance
        $this->rc->amacube           = new stdClass;
        $this->rc->amacube->errors   = array();
        $this->rc->amacube->feedback = array();

        if ($this->rc->config->get('amacube_accounts_db_enabled')) {
            // Check accounts database for catchall enabled
            if ($this->rc->config->get('amacube_accounts_db_dsn')) {
                include_once('AccountConfig.php');
                $this->amacube->account = new AccountConfig($this->rc->config->get('amacube_accounts_db_dsn'));
                // Check for account filter
                if ($this->amacube->account->initialized && isset($this->amacube->account->filter)) {
                    // Store on rcmail instance
                    $this->rc->amacube->filter = $this->amacube->account->filter;
                }

                // Check for account catchall
                if ($this->amacube->account->initialized && isset($this->amacube->account->catchall)) {
                    // Store on rcmail instance
                    $this->rc->amacube->catchall = $this->amacube->account->catchall;
                }
            }
        }

        // Load amacube backend driver
        $this->load_driver();

        if (!$this->amacube->driver->initialized) {
            $this->rc->amacube->errors[] = 'backend_initialization_error';
        } else {
            // Check if the driver support automatic user creation
            if ($this->amacube->driver->auto_create_user) {
                if ($this->amacube->driver->save()) {
                    $this->rc->amacube->feedback[] = array(
                        'type'    => 'confirmation',
                        'message' => 'policy_default_message'
                    );
                }
            }
        }

        /* Comment out original code
        // Load amavis config
        include_once('AmavisConfig.php');
        $this->amacube->config = new AmavisConfig($this->rc->config->get('amacube_db_dsn'));
        // Check for user & auto create option (disable plugin)
        if (!$this->amacube->config->initialized && $this->rc->config->get('amacube_auto_create_user') !== true) {
            return;
        }

        // Check for writing default user & config
        if (!$this->amacube->config->initialized && $this->rc->config->get('amacube_auto_create_user') === true) {
            // Check accounts database for filter enabled
            if (isset($this->rc->amacube->filter) && $this->rc->amacube->filter == false) {
                return;
            }

            // Write default user & config
            if ($this->amacube->config->write_to_db()) {
                $this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'policy_default_message');
            }
        }
        */

        // Add localization
        $this->add_texts('localization/', true);

        // Register tasks & actions
        $this->register_action('plugin.amacube-settings', array($this, 'settings_init'));

        // Add quarantine specific task and action only when we want to use quarantine
        if ($this->rc->config->get('amacube_quarantine_enabled')) {
            $this->register_task('quarantine');
            $this->register_action('plugin.amacube-quarantine', array($this, 'quarantine_init'));
        }

        // Initialize GUI
        $this->add_hook('startup', array($this, 'gui_init'));

        // Send feedback
        $this->feedback();
    }

    private function load_driver()
    {
        if (!is_object($this->amacube->driver)) {
            $driver_name  = $this->rc->config->get('amacube_driver', 'ldap');
            $driver_class = $driver_name . '_driver';

            require_once($this->home . '/drivers/amacube_driver.php');
            require_once($this->home . '/drivers/' . $driver_name . '/' . $driver_class . '.php');

            $this->amacube->driver = new $driver_class($this);
        }

        return $this->amacube->driver->initialized;
    }

    // Initialize GUI
    function gui_init()
    {
        // Add settings tab
        $this->add_hook('settings_actions', array($this, 'settings_actions'));

        if ($this->rc->config->get('amacube_quarantine_enabled')) {
            // Add taskbar button
            $this->add_button(array(
                'command'    => 'quarantine',
                'class'      => 'button-quarantine',
                'classsel'   => 'button-quarantine button-selected',
                'innerclass' => 'button-inner',
                'label'      => 'amacube.quarantine',
            ), 'taskbar');
        }

        // Add javascript
        $this->include_script('amacube.js');

        // Add stylesheet
        $skin_path = $this->local_skin_path();
        if (is_file($this->home . "/$skin_path/amacube.css")) {
            $this->include_stylesheet("$skin_path/amacube.css");
        }
    }

    // Register as settings action
    function settings_actions($args)
    {
        $args['actions'][] = array(
            'action' => 'plugin.amacube-settings',
            'class'  => 'filter-settings',
            'label'  => 'filter_settings_pagetitle',
            'domain' => 'amacube'
        );

        return $args;
    }

    // Initialize settings task
    function settings_init()
    {
        // Use standard plugin page template
        $this->register_handler('plugin.body', array($this, 'settings_display'));
        $this->rc->output->set_pagetitle(Q($this->gettext('filter_settings_pagetitle')));
        $this->rc->output->send('plugin');
    }

    // Initialize quarantine task
    function quarantine_init()
    {
        if (get_input_value('_remote', RCUBE_INPUT_POST, false) == 1) {
            // Client pagination request
            $this->quarantine_display(true);
        } else {
            // Client page request
            $this->register_handler('plugin.countdisplay', array($this, 'quarantine_display_count'));
            $this->register_handler('plugin.body', array($this, 'quarantine_display'));
            $this->rc->output->set_pagetitle(Q($this->gettext('quarantine_pagetitle')));

            // Use amacube quarantine page template
            $this->rc->output->send('amacube.quarantine');
        }
    }

    // Display settings action
    function settings_display()
    {
        // Parse form
        if (get_input_value('_token', RCUBE_INPUT_POST, false)) {
            $this->settings_post();
        }

        // Include driver class if needed
        if (!$this->load_driver()) {
            return;
        }

        // Create output
        $output_html = "";
        $output      = "";
        // Add header to output
        $output .= html::tag('h1', array('class' => 'boxtitle'), Q($this->gettext('filter_settings_pagetitle')));

        $checks = array(
            'spam'   => false,
            'virus'  => false,
            'banned' => false,
            'header' => false,
        );

        foreach (array_keys($checks) as $check) {
            $checks[$check] = $this->amacube->driver->is_supported('bypass_' . $check . '_checks');
        }

        $checks = array_filter($checks);
        if (count($checks)) {
            // Create output : table (checks)
            $output_table = new html_table(array('cols' => 2, 'cellpadding' => 3, 'class' => 'propform'));

            // Create output : table : checkboxes
            foreach (array_keys($checks) as $check) {
                $output_table->add('title', html::label('activate_' . $check . '_check', $this->gettext($check . '_check')));
                $output_table->add('', $this->_show_checkbox('activate_' . $check . '_check', $this->amacube->driver->is_active($check)));
            }

            // Create output : fieldset
            $output_legend   = html::tag('legend', null, $this->gettext('section_checks'));
            $output_fieldset = html::tag('fieldset', array('class' => 'checks'), $output_legend . $output_table->show());

            // Create output : activate
            $output_html .= $output_fieldset;
        }

        $lovers = array(
            'spam'   => false,
            'virus'  => false,
            'banned' => false,
            'header' => false,
        );

        foreach (array_keys($lovers) as $lover) {
            $lovers[$lover] = $this->amacube->driver->is_supported($lover . '_lover');
        }

        $lovers = array_filter($lovers);

        if (count($lovers)) {
            // Create output : table (delivery)
            $output_table = new html_table(array('cols' => 2, 'cellpadding' => 3, 'class' => 'propform'));

            // Create output : table : radios
            foreach (array_keys($lovers) as $lover) {
                $output_table->add('title', $this->gettext($lover . '_delivery'));
                $string  = $this->_show_radio($lover . '_delivery_deliver', $lover . '_delivery', 'deliver', $this->amacube->driver->is_delivery($lover, 'deliver')) . ' ';
                $string .= html::label($lover . '_delivery_deliver', $this->gettext('deliver'));

                if ($this->rc->config->get('amacube_quarantine_enabled')) {
                    $string .= $this->_show_radio($lover . '_delivery_quarantine', $lover . '_delivery', 'quarantine', $this->amacube->driver->is_delivery($lover, 'quarantine')) . ' ';
                    $string .= html::label($lover . '_delivery_quarantine', $this->gettext('quarantine'));
                }

                $string .= $this->_show_radio($lover . '_delivery_discard', $lover . '_delivery', 'discard', $this->amacube->driver->is_delivery($lover, 'discard'));
                $string .= html::label($lover . '_delivery_discard', $this->gettext('discard'));
                $output_table->add('', $string);
            }

            // Create output : fieldset
            $output_legend   = html::tag('legend', null, $this->gettext('section_delivery'));
            $output_fieldset = html::tag('fieldset', array('class' => 'delivery'), $output_legend . $output_table->show());

            // Create output : quarantine
            $output_html .= $output_fieldset;
        }

        $levels = array(
            'spam_tag'  => false,
            'spam_tag2' => false,
            'spam_kill' => false,
        );

        if ($this->rc->config->get('amacube_quarantine_enabled')) {
            $levels['spam_quarantine_cutoff'] = false;
        }

        foreach (array_keys($levels) as $level) {
            $levels[$level] = $this->amacube->driver->is_supported($level . '_level');
        }

        $levels = array_filter($levels);

        if (count($levels)) {
            // Create output : table (levels)
            $output_table = new html_table(array('cols' => 2, 'cellpadding' => 3, 'class' => 'propform'));

            // Create output : table : input : sa tag levels
            foreach (array_keys($levels) as $level) {
                $output_table->add('title', html::label($level . '_level', $this->gettext($level . '_level')));
                $output_table->add('', $this->_show_inputfield($level . '_level', $this->amacube->driver->policy_setting[$level . '_level']));
            }

            // Create output : fieldset
            $output_legend   = html::tag('legend', null, $this->gettext('section_levels'));
            $output_fieldset = html::tag('fieldset', array('class' => 'levels'), $output_legend . $output_table->show());

            // Create output : levels
            $output_html .= $output_fieldset;
        }

        $senders = array(
            'whitelist' => false,
            'blacklist' => false,
        );

        foreach (array_keys($senders) as $sender) {
            $senders[$sender] = $this->amacube->driver->is_supported($sender . '_sender');
        }

        $senders = array_filter($senders);

        if (count($senders)) {
            foreach (array_keys($senders) as $sender) {
                // Create output : table
                $output_table = new html_table(array('cols' => 2, 'cellpadding' => 3, 'class' => 'propform'));

                $list = $this->amacube->driver->policy_setting[$sender . '_sender'];
                if (is_array($list) && count($list) > 0) {
                    $sender_table = new html_table(array('cols' => 1));
                    foreach ($list as $id => $value) {
                        $sender_input = new html_inputfield(array(
                            'name'  => $sender . '_sender[]',
                            'id'    => $sender . '_' . $id,
                            'value' => $value,
                            'size'  => 20,
                        ));
                        $sender_table->add('', $sender_input->show());
                    }

                    $output_table->add('title', html::label($sender, $this->gettext($sender . '_sender')));
                    $output_table->add('', $sender_table->show());
                }

                // Create output : fieldset
                $output_legend   = html::tag('legend', null, $this->gettext('section_' . $sender));
                $output_fieldset = html::tag('fieldset', array('class' => $sender), $output_legend . $output_table->show());
                // Create output : levels
                $output_html .= $output_fieldset;
            }
        }

        // Create output : button
        $output_button = html::div('footerleft formbuttons', $this->rc->output->button(array(
            'command' => 'plugin.amacube-settings-post',
            'type'    => 'input',
            'class'   => 'button mainaction',
            'label'   => 'save'
        )));

        // Add form to container and container to output
        $output .= html::div(array('id' => 'preferences-details', 'class' => 'boxcontent'), $this->rc->output->form_tag(array(
            'id'     => 'amacubeform',
            'name'   => 'amacubeform',
            'class'  => 'tabbed',
            'method' => 'post',
            'action' => './?_task=settings&_action=plugin.amacube-settings',
        ), $output_html));

        // Add labels to client
        $this->rc->output->add_label(
            'amacube.activate_spam_check',
            'amacube.activate_virus_check',
            'amacube.activate_spam_quarantine',
            'amacube.activate_virus_quarantine',
            'amacube.activate_banned_quarantine',
            'amacube.spam_tag2_level',
            'amacube.spam_kill_level'
        );

        // Add button to output
        $output .= $output_button;

        // Add form to client
        $this->rc->output->add_gui_object('amacubeform', 'amacubeform');

        // Send feedback
        $this->feedback();

        // Return output
        return $output;
    }

    // Save settings action
    function settings_post()
    {
        // Get the checks post vars
        if ($this->amacube->driver->is_supported('bypass_spam_checks')) {
            $activate_spam_check  = get_input_value('activate_spam_check', RCUBE_INPUT_POST, false);
            $this->amacube->driver->policy_setting['bypass_spam_checks'] = empty($activate_spam_check);
        }

        if ($this->amacube->driver->is_supported('bypass_virus_checks')) {
            $activate_virus_check = get_input_value('activate_virus_check', RCUBE_INPUT_POST, false);
            $this->amacube_driver->policy_settings['bypass_virus_checks'] = empty($activate_virus_check);
        }
        if ($this->amacube->driver->is_supported('bypass_banned_checks')) {
            $activate_banned_check = get_input_value('activate_banned_check', RCUBE_INPUT_POST, false);
            $this->amacube_driver->policy_settings['bypass_banned_checks'] = empty($activate_banned_check);
        }
        if ($this->amacube->driver->is_supported('bypass_header_checks')) {
            $activate_header_check = get_input_value('activate_header_check', RCUBE_INPUT_POST, false);
            $this->amacube_driver->policy_settings['bypass_header_checks'] = empty($activate_header_check);
        }

        // Apply the delivery post vars
        foreach (array('spam_delivery', 'virus_delivery', 'banned_delivery', 'badheader_delivery') as $input) {
            $method = get_input_value($input, RCUBE_INPUT_POST, false);
            if ($method) {
                $delivery = explode('_', $input);
                $delivery = $delivery[0];

                if ($delivery == 'banned') {
                    $lover = $delivery . '_files';
                } elseif ($delivery == 'badheader') {
                    $lover    = 'bad_header';
                    $delivery = 'bad_header';
                } else {
                    $lover = $delivery;
                }

                switch ($method) {
                    case 'deliver':
                        $this->amacube->driver->policy_setting[$lover . '_lover']            = true;
                        $this->amacube->driver->policy_setting[$delivery . '_quarantine_to'] = false;
                        break;
                    case 'quarantine':
                        $this->amacube->driver->policy_setting[$lover . '_lover']            = false;
                        $this->amacube->driver->policy_setting[$delivery . '_quarantine_to'] = true;
                        break;
                    case 'discard':
                        $this->amacube->driver->policy_setting[$lover . '_lover']            = false;
                        $this->amacube->driver->policy_setting[$delivery . '_quarantine_to'] = false;
                        break;
                }
            }
        }

        // Get the levels post vars
        $spam_tag_level  = get_input_value('spam_tag_level', RCUBE_INPUT_POST, false);
        $spam_tag2_level = get_input_value('spam_tag2_level', RCUBE_INPUT_POST, false);
        $spam_kill_level = get_input_value('spam_kill_level', RCUBE_INPUT_POST, false);

        // Apply the levels post vars
        $tag_level_min = $this->rc->config->get('amacube_amavis_tag_level_min', -20);
        $tag_level_max = $this->rc->config->get('amacube_amavis_tag_level_max', 999);
        if (!is_numeric($spam_tag_level) || $spam_tag_level < $tag_level_min || $spam_tag_level > $tag_level_max) {
            $this->rc->amacube->errors[] = 'spam_tag_level_error';
        } else {
            $this->amacube->driver->policy_setting['spam_tag_level'] = $spam_tag2_level;
        }

        if (!is_numeric($spam_tag2_level) || $spam_tag2_level < $tag_level_min || $spam_tag2_level > $tag_level_max) {
            $this->rc->amacube->errors[] = 'spam_tag2_level_error';
        } else {
            $this->amacube->driver->policy_setting['spam_tag2_level'] = $spam_tag2_level;
        }

        if (!is_numeric($spam_kill_level) || $spam_kill_level < $tag_level_min || $spam_kill_level > $tag_level_max) {
            $this->rc->amacube->errors[] = 'spam_kill_level_error';
        } else {
            $this->amacube->driver->policy_setting['spam_kill_level'] = $spam_kill_level;
        }

        if ($this->rc->config->get('amacube_quarantine_enabled')) {
            $spam_quarantine_cutoff_level = get_input_value('spam_quarantine_cutoff_level', RCUBE_INPUT_POST, false);

            if (!is_numeric($spam_quarantine_cutoff_level) || $spam_quarantine_cutoff_level < $this->amacube->driver->policy_setting['spam_kill_level'] || $spam_kill_level > 1000) {
                $this->rc->amacube->errors[] = 'spam_quarantine_cutoff_level_error';
            } else {
                $this->amacube->driver->policy_setting['spam_quarantine_cutoff_level'] = $spam_quarantine_cutoff_level;
            }
        }

        if ($this->amacube->driver->is_supported('blacklist_sender')) {
            $whitelist_sender = get_input_value('whitelist_sender', RCUBE_INPUT_POST, false);
            write_log('amacube', sprintf("whitelist_sender: %s", var_export($whitelist_sender, true)));
        }

        if ($this->amacube->driver->is_supported('blacklist_sender')) {
            $blacklist_sender = get_input_value('blacklist_sender', RCUBE_INPUT_POST, false);
            write_log('amacube', sprintf("blacklist_sender: %s", var_export($blacklist_sender, true)));
        }

        // Verify policy config
        $verify = $this->amacube->driver->verify_policy_array();
        if (isset($verify) && is_array($verify) && !empty($verify)) {
            $this->rc->amacube->errors[] = "policy_verification_failed";
        } else {
            if ($this->amacube->driver->save()) {
                $this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'config_saved');
            } else {
                $this->rc->amacube->errors[] = "policy_save_failed";
            }
        }

    }

    // Display quarantine task
    // Used to display entire page or specified range (ajax pagination)
    function quarantine_display($ajax = false)
    {
    	$ajax = ($ajax === true) ? true : false;
		// Include quarantine class
        include_once('AmavisQuarantine.php');
        $this->amacube->quarantine = new AmavisQuarantine($this->rc->config->get('amacube_db_dsn'),
                                                 $this->rc->config->get('amacube_amavis_host'), 
                                                 $this->rc->config->get('amacube_amavis_port'));
		// Parse form
		if (get_input_value('_token', RCUBE_INPUT_POST, false)) { $this->quarantine_post(); }
												 
		$pagination = array();
		if (!$ajax) {
			$output 				= '';
	        // Get all quarantines (0:0)
	        // Used to calculate pagination based on total amount of quarantined messages
			$pagination['start']	= 0;
			$pagination['size']		= 0;
		} else {
			$output 				= array();
			// Get paged quarantines
			$pagination['current']	= get_input_value('page', RCUBE_INPUT_POST, false) ?: 1;
			$pagination['total'] 	= get_input_value('msgcount', RCUBE_INPUT_POST, false);
			if (!$pagination['current'] || !$pagination['total']) {	return; }
			
			$pagination['current']	= (int) $pagination['current'];
			$pagination['total'] 	= (int) $pagination['total'];
			$pagination['size']		= $this->rc->config->get('mail_pagesize');
			$pagination['count']	= ceil(($pagination['total'] / $pagination['size']));
			$pagination['start']	= (($pagination['current'] * $pagination['size']) - $pagination['size']);
			$pagination['stop']		= ($pagination['start'] + $pagination['size']);
		}
		$quarantines = $this->amacube->quarantine->list_quarantines($pagination['start'],$pagination['size']);
        if (!is_array($quarantines)) {
			// Send feedback
			$this->feedback();
			// Return on error
            return;
        }
        if (count($quarantines) == 0) {
        	$this->amacube->feedback[] = array('type' => 'notice', 'message' => 'quarantine_no_result');
       	}
		if (!$ajax) {
			$pagination['current'] 	= 1;
			$pagination['size']		= $this->rc->config->get('mail_pagesize');
			$pagination['count']	= ceil((count($quarantines) / $pagination['size']));
			$pagination['start']	= (($pagination['current'] * $pagination['size']) - $pagination['size']);
			$pagination['stop']		= ($pagination['start'] + $pagination['size']);
			$pagination['total'] 	= count($quarantines);
		}
		// Pagination string
		$pagination['begin'] 		= ($pagination['start']+1);
		$pagination['end'] 			= ($pagination['total'] <= $pagination['size']) ? $pagination['total'] : (($pagination['stop'] > $pagination['total']) ? $pagination['total'] : $pagination['stop']);
		if (count($quarantines) == 0) {
			$string					= Q($this->gettext('quarantine_no_result'));
		} else {
			$string					= Q($this->gettext('messages')).' '.$pagination['begin'].' '.Q($this->gettext('to')).' '.$pagination['end'].' '.Q($this->gettext('of')).' '.$pagination['total'];
		}
		if (!$ajax) {
			// Store locally for template use (js include not loaded yet; command unavailable)
			$this->rc->amacube->pagecount_string = $string;
		} else {
			$this->rc->output->command('amacube.messagecount',$string);
		}
		// Pagination env
		$this->rc->output->set_env('page', $pagination['current']);
		$this->rc->output->set_env('pagecount', $pagination['count']);
		$this->rc->output->set_env('msgcount', $pagination['total']);
		// Create output
		if (!$ajax) {
	        // Create output : header table
	        $messages_table = new html_table(array(
	        	'cols' 				=> 7,
	        	'id'				=> 'messagelist',
	        	'class' 			=> 'records-table messagelist sortheader fixedheader quarantine-messagelist'
			));
	        // Create output : table : headers
	        $messages_table->add_header('release',Q($this->gettext('release')));
	        $messages_table->add_header('delete',Q($this->gettext('delete')));
	        $messages_table->add_header('received',Q($this->gettext('received')));
	        $messages_table->add_header('subject',Q($this->gettext('subject')));
	        $messages_table->add_header('sender',Q($this->gettext('sender')));
	        $messages_table->add_header('type',Q($this->gettext('mailtype')));
	        $messages_table->add_header('level',Q($this->gettext('spamlevel')));
		}
		// Create output : table : rows
        foreach ($quarantines as $key => $value) {
        	if (!$ajax) {
	        	if ($key >= $pagination['start'] && $key < $pagination['stop']) {
		            $messages_table->add('release',$this->_show_radio('rel_'.$quarantines[$key]['id'],$quarantines[$key]['id'],'_rel_'.$quarantines[$key]['id']));
		            $messages_table->add('delete',$this->_show_radio('del_'.$quarantines[$key]['id'],$quarantines[$key]['id'],'_del_'.$quarantines[$key]['id']));
		            $messages_table->add('date',Q(date('Y-m-d H:i:s',$quarantines[$key]['received'])));
		            $messages_table->add('subject',Q($quarantines[$key]['subject']));
		            $messages_table->add('sender',Q($quarantines[$key]['sender']));
		            $messages_table->add('type',Q($this->gettext('content_decode_'.$quarantines[$key]['content'])));
		            $messages_table->add('level',Q($quarantines[$key]['level']));
	        	}        		
        	} else {
				$string 			= '<tr>';
				$string				.= '<td class="release">'.$this->_show_radio('rel_'.$quarantines[$key]['id'],$quarantines[$key]['id'],'_rel_'.$quarantines[$key]['id']).'</td>';
				$string				.= '<td class="delete">'.$this->_show_radio('del_'.$quarantines[$key]['id'],$quarantines[$key]['id'],'_del_'.$quarantines[$key]['id']).'</td>';
				$string				.= '<td class="date">'.Q(date('Y-m-d H:i:s',$quarantines[$key]['received'])).'</td>';
				$string				.= '<td class="subject">'.Q($quarantines[$key]['subject']).'</td>';
				$string				.= '<td class="sender">'.Q($quarantines[$key]['sender']).'</td>';
				$string				.= '<td class="type">'.Q($this->gettext('content_decode_'.$quarantines[$key]['content'])).'</td>';
				$string				.= '<td class="level">'.Q($quarantines[$key]['level']).'</td>';
				$string				.= '</tr>';
				$output[]			= $string;
        	}
        }
		if (!$ajax) {
			// Create output : table form
	        $output_table_form = $this->rc->output->form_tag(array(
	            'id' => 'quarantineform',
	            'name' => 'quarantineform',
	            'method' => 'post',
	            'action' => './?_task=quarantine&_action=plugin.amacube-quarantine',
			), $messages_table->show());
			// Add table container form to output
			$output .= $output_table_form;
	        // Add form to client
	        $this->rc->output->add_gui_object('quarantineform', 'quarantineform');
		} else {
			// Send list command
			$this->rc->output->command('amacube.messagelist',array('messages' => $output));
			// Send page commands
			if ($pagination['current'] > 1) {
				// Enable first & previous
				$this->rc->output->command('amacube.page','first','enabled');
				$this->rc->output->command('amacube.page','previous','enabled');
			} else {
				// Disable first & previous
				$this->rc->output->command('amacube.page','first','disabled');
				$this->rc->output->command('amacube.page','previous','disabled');
			}
			if ($pagination['current'] < $pagination['count']) {
				// Enable next & last
				$this->rc->output->command('amacube.page','next','enabled');
				$this->rc->output->command('amacube.page','last','enabled');
			} else {
				// Disable next & last
				$this->rc->output->command('amacube.page','next','disabled');
				$this->rc->output->command('amacube.page','last','disabled');
			}
			// Set output to nothing because client commands were used
			$output = '';
		}
		// Feedback
		$this->feedback();
		return $output;
    }

    function quarantine_display_count()
    {
        return html::span(array('id' => 'rcmcountdisplay', 'class' => 'countdisplay quarantine-countdisplay'), $this->rc->amacube->pagecount_string);
    }

    function quarantine_post()
    {
		// Process quarantine
        $delete = array();
        $release = array();
        foreach ($_POST as $key => $value) {
            if (preg_match('/_([dr]el)_([\w\-]+)/', $value, $matches)) {
                if ($matches[1] == 'del') { array_push($delete, $matches[2]); }
                elseif ($matches[1] == 'rel') { array_push($release, $matches[2]); }
            }
        }
		// Intersection error (should no longer happen with radio inputs but still)
        $intersect = array_intersect($delete, $release);
        if (is_array($intersect) && count($intersect) > 0) {
			$this->rc->amacube->errors[] = 'intersection_error';
			$this->rc->output->send('amacube.quarantine');
            return;
        }
		// Process released
		if (!empty($release)) {
			if ($this->amacube->quarantine->release($release)) {
				$this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'success_release');
			}
		}
		// Process deleted
		if (!empty($delete)) {
			if ($this->amacube->quarantine->delete($delete)) {
				$this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'success_delete');
			}
		}
    }

    function feedback()
    {
        // Send first error or feedbacks to client
        if (!empty($this->rc->amacube->errors)) {
            $this->rc->output->command('display_message', Q($this->gettext($this->rc->amacube->errors[0])), 'error');
        } elseif (!empty($this->rc->amacube->feedback)) {
            foreach ($this->rc->amacube->feedback as $feed) {
                if (!empty($feed)) {
                    $this->rc->output->command('display_message', Q($this->gettext($feed['message'])), $feed['type']);
                }
            }
        }
    }

    // CONVENIENCE METHODS
    // This bloody html_checkbox class will always return checkboxes that are "checked"
    // I did not figure out how to prevent that $$*@@!!
    // so I used html::tag instead...
    function _show_checkbox($id, $checked = false)
    {
        $attr_array = array('name' => $id,'id' => $id);
        if ($checked) {
            $attr_array['checked'] = 'checked';
        }

        //$box = new html_checkbox($attr_array);
        $attr_array['type'] = 'checkbox';

        $box = html::tag('input', $attr_array);
        return $box;
    }

    function _show_radio($id, $name, $value, $checked = false)
    {
        $attr_array = array('name' => $name,'id' => $id);
        if ($checked) {
            $attr_array['checked'] = 'checked';
        }

        //$box = new html_checkbox($attr_array);
        $attr_array['type']  = 'radio';
        $attr_array['value'] = $value;

        $box = html::tag('input', $attr_array);
        return $box;
    }

    function _show_inputfield($id, $value)
    {
        $input = new html_inputfield(array(
                'name'  => $id,
                'id'    => $id,
                'value' => $value,
                'size'  => 10
        ));
        return $input->show();
    }
}
?>
