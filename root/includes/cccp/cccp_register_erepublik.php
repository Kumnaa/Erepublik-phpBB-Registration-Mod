<?php
/**
*
* @package cccp
* @version $Id$
* @copyright (c) 2005 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* cccp_register
* Board registration
* @package ucp
*/
class cccp_register_erepublik
{
    var $u_action;

    function main($id, $mode)
    {
        global $config, $db, $user, $auth, $template, $phpbb_root_path, $phpEx;

        //$debug = true;
		
        require($phpbb_root_path . 'includes/cccp/erep_api.' . $phpEx);
        require($phpbb_root_path . 'includes/cccp/erep_api_config.' . $phpEx);
        
        $user->add_lang('mods/cccp/registration');

        include($phpbb_root_path . 'includes/functions_profile_fields.' . $phpEx);

        $cccp_erep_reg_key = request_var('oauth_token','');
        $cccp_erep_reg_key_verifier = request_var('oauth_verifier','');

        try {
            $erep = new erep_api(erep_api_config::$consumer_key, erep_api_config::$consumer_secret);
            $erep_user = $erep->get_citizen_data($cccp_erep_reg_key, $cccp_erep_reg_key_verifier);
        } catch (Exception $e) {
            trigger_error($user->lang['CCCP_EREP_API_ERROR']);
        }

        $sql = 'SELECT user_password, user_email, group_id, user_timezone,
                user_dst, user_lang, user_type, user_actkey, user_ip, user_regdate,
                user_inactive_reason, user_inactive_time, new_password
                FROM ' . CCCP_EREP_REGISTRATION_TABLE . "
                WHERE cccp_erep_token = '". $db->sql_escape($cccp_erep_reg_key) ."'";

        $result = $db->sql_query($sql);
        $new_user_data = $db->sql_fetchrow($result);
        if (count($new_user_data) > 0 && strlen($erep_user->name) > 0)
        {
            $new_password = $new_user_data['new_password'];
            unset($new_user_data['new_password']);
            $new_user_data["username"] = (string)trim($erep_user->name);
            $new_user_data["erepublik_id"] = (int)trim($erep_user->id);
            
            $user_sql = 'SELECT username
                    FROM ' . USERS_TABLE . "
                    WHERE username = '". $db->sql_escape($new_user_data["username"]) ."'";
            $result = $db->sql_query($user_sql);
            $user_name_validator = $db->sql_fetchrow($result);
            
            if (is_array($user_name_validator))
            {
                $valid_username = false;
            }
            else
            {
                $valid_username = true;
                // Register user...
                $user_id = user_add($new_user_data);
            }

            $sql = 'DELETE FROM ' . CCCP_EREP_REGISTRATION_TABLE . " WHERE  cccp_erep_token = '". $db->sql_escape($cccp_erep_reg_key) ."'";
            $db->sql_query($sql);

            if ($valid_username === true)
            {
                // This should not happen, because the required variables are listed above...
                if ($user_id === false)
                {
					trigger_error('NO_USER', E_USER_ERROR);
                }
                
                $server_url = generate_board_url();
                
                if ($coppa && $config['email_enable'])
                {
					$message = $user->lang['ACCOUNT_COPPA'];
					$email_template = 'coppa_welcome_inactive';
                }
                else if ($config['require_activation'] == USER_ACTIVATION_SELF && $config['email_enable'])
                {
					$message = $user->lang['ACCOUNT_INACTIVE'];
					$email_template = 'user_welcome_inactive';
                }
                else if ($config['require_activation'] == USER_ACTIVATION_ADMIN && $config['email_enable'])
                {
					$message = $user->lang['ACCOUNT_INACTIVE_ADMIN'];
					$email_template = 'admin_welcome_inactive';
                }
                else
                {
					$message = $user->lang['ACCOUNT_ADDED'];
					$email_template = 'user_welcome';
                }

                if ($config['email_enable'])
                {
                        include_once($phpbb_root_path . 'includes/functions_messenger.' . $phpEx);

                        $messenger = new messenger(false);

                        $messenger->template($email_template, $new_user_data['user_lang']);

                        $messenger->to($new_user_data['user_email'], $new_user_data['username']);

                        $messenger->headers('X-AntiAbuse: Board servername - ' . $config['server_name']);
                        $messenger->headers('X-AntiAbuse: User_id - ' . $user->data['user_id']);
                        $messenger->headers('X-AntiAbuse: Username - ' . $user->data['username']);
                        $messenger->headers('X-AntiAbuse: User IP - ' . $user->ip);

                        $messenger->assign_vars(array(
                                'WELCOME_MSG'    => htmlspecialchars_decode(sprintf($user->lang['WELCOME_SUBJECT'], $config['sitename'])),
                                'USERNAME'        => htmlspecialchars_decode($new_user_data['username']),
                                'PASSWORD'        => htmlspecialchars_decode($new_password),
                                'U_ACTIVATE'    => "$server_url/ucp.$phpEx?mode=activate&u=$user_id&k=". $new_user_data['user_actkey'])
                        );

                        if ($coppa)
                        {
                                $messenger->assign_vars(array(
                                        'FAX_INFO'        => $config['coppa_fax'],
                                        'MAIL_INFO'        => $config['coppa_mail'],
                                        'EMAIL_ADDRESS'    => $new_user_data['user_email'])
                                );
                        }

                        $messenger->send(NOTIFY_EMAIL);

                        if ($config['require_activation'] == USER_ACTIVATION_ADMIN)
                        {
                                // Grab an array of user_id's with a_user permissions ... these users can activate a user
                                $admin_ary = $auth->acl_get_list(false, 'a_user', false);
                                $admin_ary = (!empty($admin_ary[0]['a_user'])) ? $admin_ary[0]['a_user'] : array();

                                // Also include founders
                                $where_sql = ' WHERE user_type = ' . USER_FOUNDER;

                                if (sizeof($admin_ary))
                                {
                                        $where_sql .= ' OR ' . $db->sql_in_set('user_id', $admin_ary);
                                }

                                $sql = 'SELECT user_id, username, user_email, user_lang, user_jabber, user_notify_type
                                        FROM ' . USERS_TABLE . ' ' .
                                        $where_sql;
                                $result = $db->sql_query($sql);

                                while ($row = $db->sql_fetchrow($result))
                                {
                                        $messenger->template('admin_activate', $row['user_lang']);
                                        $messenger->to($row['user_email'], $row['username']);
                                        $messenger->im($row['user_jabber'], $row['username']);

                                        $messenger->assign_vars(array(
                                                'USERNAME'            => htmlspecialchars_decode($new_user_data['username']),
                                                'U_USER_DETAILS'    => "$server_url/memberlist.$phpEx?mode=viewprofile&u=$user_id",
                                                'U_ACTIVATE'        => "$server_url/ucp.$phpEx?mode=activate&u=$user_id&k=". $new_user_data['user_actkey'])
                                        );

                                        $messenger->send($row['user_notify_type']);
                                }
                                $db->sql_freeresult($result);
                        }
                }
            }
            else
            {
                $message = $user->lang['CCCP_USERNAME_EXISTS'];
				if ($debug == true)
				{
					echo 'API fetched username: '. $erep_user->name .'<br />
						Username pulled from existing DB: '. print_r($user_name_validator, true) .'<br />
						Rows fetched from the DB: '. count($new_user_data) .'<br />
						From SQL: '. $user_sql;
					die();
				}
            }
            $message = $message . '<br /><br />' . sprintf($user->lang['RETURN_INDEX'], '<a href="' . append_sid("{$phpbb_root_path}index.$phpEx") . '">', '</a>');
            trigger_error($message);
        }
        trigger_error($user->lang['CCCP_ADD_USER_ERROR']);
    }
}

?>