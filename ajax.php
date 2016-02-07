<?php

define('BB_SCRIPT', 'ajax');
define('IN_AJAX', true);

$ajax = new ajax_common();

require('./common.php');

$ajax->init();

// Init userdata
$user->session_start();

// Exit if board is disabled via ON/OFF trigger or by admin
if ($ajax->action != 'manage_admin')
{
	if ($bb_cfg['board_disable'])
	{
		$ajax->ajax_die($lang['BOARD_DISABLE']);
	}
	else if (file_exists(BB_DISABLED))
	{
		$ajax->ajax_die($lang['BOARD_DISABLE_CRON']);
	}
}

// Load actions required modules
switch ($ajax->action)
{
	case 'view_post':
		require(INC_DIR . 'bbcode.php');
		break;

	case 'posts':
	case 'post_mod_comment':
		require(INC_DIR . 'bbcode.php');
		require(INC_DIR . 'functions_post.php');
		require(INC_DIR . 'functions_admin.php');
		break;

	case 'view_torrent':
	case 'mod_action':
	case 'change_tor_status':
	case 'change_torrent':
	case 'gen_passkey':
		require(INC_DIR . 'functions_torrent.php');
		break;

	case 'user_register':
		require(INC_DIR . 'functions_validate.php');
		break;

	case 'manage_user':
	case 'manage_admin':
		require(INC_DIR . 'functions_admin.php');
		break;

	case 'group_membership':
	case 'manage_group':
		require(INC_DIR . 'functions_group.php');
		break;

	case 'sitemap';
		require(CLASS_DIR .'sitemap.php');
		break;
}

// Position in $ajax->valid_actions['xxx']
define('AJAX_AUTH', 0); // 'guest', 'user', 'mod', 'admin', 'super_admin'

$ajax->exec();

//
// Ajax
//
class ajax_common
{
	var $request = [];
	var $response = [];

	var $valid_actions = [
		// ACTION NAME         AJAX_AUTH
		'edit_user_profile' => ['admin'],
		'change_user_rank'  => ['admin'],
		'change_user_opt'   => ['admin'],
		'manage_user'       => ['admin'],
		'manage_admin'      => ['admin'],
		'sitemap'           => ['admin'],

		'mod_action'        => ['mod'],
		'topic_tpl'         => ['mod'],
		'group_membership'  => ['mod'],
		'post_mod_comment'  => ['mod'],

		'avatar'            => ['user'],
		'gen_passkey'       => ['user'],
		'change_torrent'    => ['user'],
		'change_tor_status' => ['user'],
		'manage_group'      => ['user'],

		'view_post'         => ['guest'],
		'view_torrent'      => ['guest'],
		'user_register'     => ['guest'],
		'posts'             => ['guest'],
		'index_data'        => ['guest'],
	];

	var $action = null;

	/**
	 *  Constructor
	 */
	function ajax_common()
	{
		ob_start([&$this, 'ob_handler']);
		header('Content-Type: text/plain');
	}

	/**
	 *  Perform action
	 */
	function exec()
	{
		global $lang;

		// Exit if we already have errors
		if (!empty($this->response['error_code']))
		{
			$this->send();
		}

		// Check that requested action is valid
		$action = $this->action;

		if (!$action || !is_string($action))
		{
			$this->ajax_die('no action specified');
		}
		elseif (!$action_params =& $this->valid_actions[$action])
		{
			$this->ajax_die('invalid action: ' . $action);
		}

		// Auth check
		switch ($action_params[AJAX_AUTH])
		{
			// GUEST
			case 'guest':
				break;

			// USER
			case 'user':
				if (IS_GUEST)
				{
					$this->ajax_die($lang['NEED_TO_LOGIN_FIRST']);
				}
				break;

			// MOD
			case 'mod':
				if (!IS_AM)
				{
					$this->ajax_die($lang['ONLY_FOR_MOD']);
				}
				$this->check_admin_session();
				break;

			// ADMIN
			case 'admin':
				if (!IS_ADMIN)
				{
					$this->ajax_die($lang['ONLY_FOR_ADMIN']);
				}
				$this->check_admin_session();
				break;

			// SUPER_ADMIN
			case 'super_admin':
				if (!IS_SUPER_ADMIN)
				{
					$this->ajax_die($lang['ONLY_FOR_SUPER_ADMIN']);
				}
				$this->check_admin_session();
				break;

			default:
				trigger_error("invalid auth type for $action", E_USER_ERROR);
		}

		// Run action
		$this->$action();

		// Send output
		$this->send();
	}

	/**
	 *  Exit on error
	 *
	 * @param     $error_msg
	 * @param int $error_code
	 */
	function ajax_die($error_msg, $error_code = E_AJAX_GENERAL_ERROR)
	{
		$this->response['error_code'] = $error_code;
		$this->response['error_msg'] = $error_msg;

		$this->send();
	}

	/**
	 *  Initialization
	 */
	function init()
	{
		$this->request = $_POST;
		$this->action =& $this->request['action'];
	}

	/**
	 *  Send data
	 */
	function send()
	{
		$this->response['action'] = $this->action;

		if (DBG_USER && SQL_DEBUG && !empty($_COOKIE['sql_log']))
		{
			$this->response['sql_log'] = get_sql_log();
		}

		// sending output will be handled by $this->ob_handler()
		exit();
	}

	/**
	 *  OB Handler
	 *
	 * @param $contents
	 *
	 * @return string
	 */
	function ob_handler($contents)
	{
		if (DBG_USER)
		{
			if ($contents)
			{
				$this->response['raw_output'] = $contents;
			}
		}

		$response_js = \Zend\Json\Json::encode($this->response);

		if (GZIP_OUTPUT_ALLOWED && !defined('NO_GZIP'))
		{
			if (UA_GZIP_SUPPORTED && strlen($response_js) > 2000)
			{
				header('Content-Encoding: gzip');
				$response_js = gzencode($response_js, 1);
			}
		}

		return $response_js;
	}

	/**
	 *  Admin session
	 */
	function check_admin_session()
	{
		global $user;

		if (!$user->data['session_admin'])
		{
			if (empty($this->request['user_password']))
			{
				$this->prompt_for_password();
			}
			else
			{
				$login_args = [
					'login_username' => $user->data['username'],
					'login_password' => $_POST['user_password'],
				];
				if (!$user->login($login_args, true))
				{
					$this->ajax_die('Wrong password');
				}
			}
		}
	}

	/**
	 *  Prompt for password
	 */
	function prompt_for_password()
	{
		$this->response['prompt_password'] = 1;
		$this->send();
	}

	/**
	 *  Prompt for confirmation
	 *
	 * @param $confirm_msg
	 */
	function prompt_for_confirm($confirm_msg)
	{
		if (empty($confirm_msg)) $this->ajax_die('false');

		$this->response['prompt_confirm'] = 1;
		$this->response['confirm_msg'] = $confirm_msg;
		$this->send();
	}

	/**
	 *  Verify mod rights
	 *
	 * @param $forum_id
	 */
	function verify_mod_rights($forum_id)
	{
		global $userdata, $lang;

		$is_auth = auth(AUTH_MOD, $forum_id, $userdata);

		if (!$is_auth['auth_mod'])
		{
			$this->ajax_die($lang['ONLY_FOR_MOD']);
		}
	}

	function edit_user_profile()
	{
		require(AJAX_DIR . 'edit_user_profile.php');
	}

	function change_user_rank()
	{
		require(AJAX_DIR . 'change_user_rank.php');
	}

	function change_user_opt()
	{
		require(AJAX_DIR . 'change_user_opt.php');
	}

	function gen_passkey()
	{
		require(AJAX_DIR . 'gen_passkey.php');
	}

	function group_membership()
	{
		require(AJAX_DIR . 'group_membership.php');
	}

	function manage_group()
	{
		require(AJAX_DIR . 'edit_group_profile.php');
	}

	function post_mod_comment()
	{
		require(AJAX_DIR . 'post_mod_comment.php');
	}

	function view_post()
	{
		require(AJAX_DIR . 'view_post.php');
	}

	function change_tor_status()
	{
		require(AJAX_DIR . 'change_tor_status.php');
	}

	function change_torrent()
	{
		require(AJAX_DIR . 'change_torrent.php');
	}

	function view_torrent()
	{
		require(AJAX_DIR . 'view_torrent.php');
	}

	function user_register()
	{
		require(AJAX_DIR . 'user_register.php');
	}

	function mod_action()
	{
		require(AJAX_DIR . 'mod_action.php');
	}

	function posts()
	{
		require(AJAX_DIR . 'posts.php');
	}

	function manage_user()
	{
		require(AJAX_DIR . 'manage_user.php');
	}

	function manage_admin()
	{
		require(AJAX_DIR . 'manage_admin.php');
	}

	function topic_tpl()
	{
		require(AJAX_DIR . 'topic_tpl.php');
	}

	function index_data()
	{
		require(AJAX_DIR . 'index_data.php');
	}

	function avatar()
	{
		require(AJAX_DIR . 'avatar.php');
	}

	function sitemap()
	{
		require(AJAX_DIR .'sitemap.php');
	}
}