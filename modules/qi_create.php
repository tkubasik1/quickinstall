<?php
/**
*
* @package quickinstall
* @version $Id$
* @copyright (c) 2007, 2008 eviL3
* @copyright (c) 2010 Jari Kanerva (tumba25)
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_QUICKINSTALL'))
{
	exit;
}

/**
 * qi_create module
 */
class qi_create
{
	public function __construct()
	{
		global $db, $user, $auth, $cache, $settings, $table_prefix;
		global $quickinstall_path, $phpbb_root_path, $phpEx, $config, $msg_title;

		// include installation functions
		include($quickinstall_path . 'includes/functions_install.' . $phpEx);
		// postgres uses remove_comments function which is defined in functions_admin
		include($phpbb_root_path . 'includes/functions_admin.' . $phpEx);

		$config = array_merge($config, array(
			'rand_seed'				=> md5(mt_rand()),
			'rand_seed_last_update'	=> time(),
		));

		// load installer lang
		qi::add_lang('phpbb');

		// phpbb's install uses $lang instead of $user->lang
		// need to use $GLOBALS here
		$GLOBALS['lang'] = &$user->lang;
		global $lang;

		// request variables
		$dbname			= htmlspecialchars_decode($settings->get_config('dbname', '', true));
		$redirect		= $settings->get_config('redirect', false);
		$drop_db		= $settings->get_config('drop_db', false);
		$delete_files	= $settings->get_config('delete_files', false);
		$automod		= $settings->get_config('automod', false);
		$make_writable	= $settings->get_config('make_writable', false);
		$grant_permissions	= octdec($settings->get_config('grant_permissions', 0));
		$populate		= $settings->get_config('populate', false);
		$subsilver		= $settings->get_config('subsilver', 0);
		$alt_env		= $settings->get_config('alt_env', '');
		$pop_data		= $settings->get_config('pop_data', array('' => ''));
		$other_config	= request_var('other_config', ''); // Revisit

		// Some populate checking
		if ($populate)
		{
			if (empty($pop_data['num_users']) && empty($pop_data['num_cats']) && empty($pop_data['num_forums']) && empty($pop_data['num_topics']) && empty($pop_data['num_replies']))
			{
				// populate with nothing?
				$populate = false;
			}
			else
			{
				$pop_data['email_domain'] = trim($pop_data['email_domain']);
				if (!empty($pop_data['num_users']) && empty($pop_data['email_domain']))
				{
					trigger_error($user->lang['NEED_EMAIL_DOMAIN'], E_USER_ERROR);
				}
			}
		}

		// Revisit
		//foreach (array('site_name', 'site_desc', 'admin_name', 'admin_pass', 'db_prefix') as $r)
		//{
		//	if ($_r = request_var($r, '', true))
		//	{
		//		$qi_config[$r] = $_r;
		//	}
		//}

		if ($alt_env !== '' && !file_exists($quickinstall_path . 'sources/phpBB3_alt/' . $alt_env))
		{
			trigger_error('NO_ALT_ENV');
		}

		// Set up our basic founder.
		$user->data['user_id'] = 2; //
		$user->data['username'] = $settings->get_config('admin_name');
		$user->data['user_colour'] = 'AA0000';

		// overwrite some of them ;)
		$user->lang = array_merge($user->lang, array(
			'CONFIG_SITE_DESC'	=> $settings->get_config('site_desc'),
			'CONFIG_SITENAME'	=> $settings->get_config('site_name'),
		));

		// smaller ^^
		$dbms = $settings->get_config('dbms');

		// check if we have a board db (and folder) name
		if (!$dbname)
		{
			trigger_error('NO_DB');
		}

		// Set the new board as root path.
		$board_dir = $settings->get_boards_dir() . $dbname . '/';
		$board_url = $settings->get_boards_url() . $dbname . '/';
		$phpbb_root_path = $board_dir;
		if (!defined('PHPBB_ROOT_PATH'))
		{
			define('PHPBB_ROOT_PATH', $board_dir);
		}

		if (file_exists($board_dir))
		{
			if ($delete_files)
			{
				file_functions::delete_dir($board_dir);
			}
			else
			{
				trigger_error(sprintf($user->lang['DIR_EXISTS'], $board_dir));
			}
		}

		// copy all of our files
		file_functions::copy_dir($quickinstall_path . 'sources/' . ($alt_env === '' ? 'phpBB3/' : "phpBB3_alt/$alt_env/"), $board_dir);

		// Now make sure we have a valid db-name and prefix
		$db_prefix = validate_dbname($settings->get_config('db_prefix'), true);
		$dbname = validate_dbname($dbname);

		// copy qi's lang file for the log
		if (file_exists("{$quickinstall_path}language/" . $settings->get_config('qi_lang') . "/info_acp_qi.$phpEx") && file_exists("{$board_dir}language/" . $settings->get_config('qi_lang')))
		{
			copy("{$quickinstall_path}language/" . $settings->get_config('qi_lang') . "/info_acp_qi.$phpEx", "{$board_dir}language/" . $settings->get_config('qi_lang') . "/mods/info_acp_qi.$phpEx");
		}
		else
		{
			copy("{$quickinstall_path}language/en/info_acp_qi.$phpEx", "{$board_dir}language/en/mods/info_acp_qi.$phpEx");
		}

		if ($dbms == 'sqlite')
		{
			$dbhost = $settings->get_config('dbhost') . $settings->get_config('db_prefix') . $dbname;
		}
		else if ($dbms == 'firebird')
		{
			$dbhost = $settings->get_config('db_prefix') . $dbname;

			// temp remove some
			list($db_prefix, $dbname, $temp1, $temp2) = array('', '', &$db_prefix, &$dbname);
		}
		else
		{
			$dbhost = $settings->get_config('dbhost');
		}

		// Set the new board as language path to get language files from outside phpBB
		$user->set_custom_lang_path($phpbb_root_path . 'language/');

		// Write to config.php ;)
		$config_data = "<?php\n";
		$config_data .= "// phpBB 3.0.x auto-generated configuration file\n// Do not change anything in this file!\n";
		$config_data_array = array(
			'dbms'				=> $dbms,
			'dbhost'			=> $dbhost,
			'dbport'			=> $settings->get_config('dbport'),
			'dbname'			=> $settings->get_config('db_prefix') . $dbname,
			'dbuser'			=> $settings->get_config('dbuser'),
			'dbpasswd'			=> htmlspecialchars_decode($settings->get_config('dbpasswd')),
			'table_prefix'		=> $table_prefix,
			'acm_type'			=> 'file',
			'load_extensions'	=> '',
		);

		foreach ($config_data_array as $key => $value)
		{
			$config_data .= "\${$key} = '" . str_replace("'", "\\'", str_replace('\\', '\\\\', $value)) . "';\n";
		}
		unset($config_data_array);

		$config_data .= "\n@define('PHPBB_INSTALLED', true);\n";
		$config_data .= "@define('DEBUG', true);\n";
		$config_data .= "@define('DEBUG_EXTRA', true);\n";
		$config_data .= '?' . '>'; // Done this to prevent highlighting editors getting confused!
		file_put_contents($board_dir . 'config.' . $phpEx, $config_data);

		if ($dbms == 'firebird')
		{
			// and now restore
			list($db_prefix, $dbname) = array($temp1, $temp2);
		}

		// update phpbb_root_path
		$phpbb_root_path = $board_dir;

		$db = db_connect();

		if ($drop_db)
		{
			$db->sql_query('DROP DATABASE IF EXISTS ' . $settings->get_config('db_prefix') . $dbname);
		}
		else
		{
			// Check if the database exists.
			if ($dbms == 'sqlite')
			{
				$db_check = $db->sql_select_db($settings->get_config('dbhost'));
			}
			else if ($dbms == 'firebird')
			{
				$db_check = $db->sql_select_db($settings->get_cache_dir() . $settings->get_config('db_prefix') . $dbname);
			}
			else if ($dbms == 'postgres')
			{
				global $sql_db, $dbhost, $dbuser, $dbpasswd, $dbport;
				$error_collector = new phpbb_error_collector();
				$error_collector->install();
				$db_check_conn = new $sql_db();
				$db_check_conn->sql_connect($dbhost, $dbuser, $dbpasswd, $settings->get_config('db_prefix') . $dbname, $dbport, false, false);
				$error_collector->uninstall();
				$db_check = count($error_collector->errors) == 0;
			}
			else
			{
				$db_check = $db->sql_select_db($settings->get_config('db_prefix') . $dbname);
			}

			if ($db_check)
			{
				trigger_error(sprintf($user->lang['DB_EXISTS'], $settings->get_config('db_prefix') . $dbname));
			}
		}

		if ($dbms == 'sqlite')
		{
			$db->sql_create_db($settings->get_config('dbhost'));
			$db->sql_select_db($settings->get_config('dbhost'));
		}
		else if ($dbms == 'firebird')
		{
			$db->sql_query('CREATE DATABASE ' . $settings->get_cache_dir() . $settings->get_config('db_prefix') . $dbname);
			$db->sql_select_db($settings->get_cache_dir() . $settings->get_config('db_prefix') . $dbname);
		}
		else if ($dbms == 'postgres')
		{
			$db->sql_query('CREATE DATABASE ' . $settings->get_config('db_prefix') . $dbname);
			$db = new $sql_db();
			$db->sql_connect($dbhost, $dbuser, $dbpasswd, $settings->get_config('db_prefix') . $dbname, $dbport, false, false);
			$db->sql_return_on_error(true);
		}
		else
		{
			$db->sql_query('CREATE DATABASE ' . $settings->get_config('db_prefix') . $dbname);
			$db->sql_select_db($settings->get_config('db_prefix') . $dbname);
		}

		// include install lang fom phpbb
		qi::add_lang('install', $phpbb_root_path . 'language/' . $settings->get_config('default_lang') . '/');

		// perform sql
		load_schema($phpbb_root_path . 'install/schemas/', $dbms);

		$current_time = time();
		$user_ip = (!empty($_SERVER['REMOTE_ADDR'])) ? htmlspecialchars($_SERVER['REMOTE_ADDR']) : '';

		$script_path = (!empty($_SERVER['PHP_SELF'])) ? $_SERVER['PHP_SELF'] : getenv('PHP_SELF');
		if (!$script_path)
		{
			$script_path = (!empty($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_URI'] : getenv('REQUEST_URI');
		}

		// Replace backslashes and doubled slashes (could happen on some proxy setups)
		$script_path = str_replace(array('\\', '//'), '/', $script_path);
		$script_path = trim(dirname($script_path));

		// add the dbname to script path
		$script_path .= $settings->get_boards_dir() . $dbname . '/';

		$config_ary = array(
			'board_startdate'	=> $current_time,
			'default_lang'		=> $settings->get_config('default_lang'),
			'server_name'		=> $settings->get_config('server_name'),
			'server_port'		=> $settings->get_config('server_port', 0),
			'board_email'		=> $settings->get_config('board_email'),
			'board_contact'		=> $settings->get_config('board_email'),
			'cookie_domain'		=> $settings->get_config('cookie_domain'),
			'default_dateformat'=> $user->lang['default_dateformat'],
			'email_enable'		=> $settings->get_config('email_enable', 0),
			'smtp_delivery'		=> $settings->get_config('smtp_delivery', 0),
			'smtp_host'			=> $settings->get_config('smtp_host'),
			'smtp_auth_method'	=> $settings->get_config('smtp_auth'),
			'smtp_username'		=> $settings->get_config('smtp_user'),
			'smtp_port'			=> $settings->get_config('smtp_port', 0),
			'smtp_password'		=> $settings->get_config('smtp_pass'),
			'cookie_secure'		=> $settings->get_config('cookie_secure', 0),
			'script_path'		=> $script_path,
			'server_protocol'	=> $settings->get_server_protocol(),
			'newest_username'	=> $settings->get_config('admin_name'),
			'avatar_salt'		=> md5(mt_rand()),
			'cookie_name'		=> 'phpbb3_' . strtolower(gen_rand_string(5)),
		);

		if (@extension_loaded('gd') || can_load_dll('gd'))
		{
			$config_ary['captcha_gd'] = 1;
		}

		foreach ($config_ary as $config_name => $config_value)
		{
			set_config($config_name, $config_value);
		}

		// Set default config and post data, this applies to all DB's
		$sql_ary = array(
			"UPDATE {$table_prefix}users
				SET username = '" . $db->sql_escape($settings->get_config('admin_name')) . "', user_password='" . $db->sql_escape(md5($settings->get_config('admin_pass'))) . "', user_ip = '" . $db->sql_escape($user_ip) . "', user_lang = '" . $db->sql_escape($settings->get_config('default_lang')) . "', user_email='" . $db->sql_escape($settings->get_config('board_email')) . "', user_dateformat='" . $db->sql_escape($user->lang['default_dateformat']) . "', user_email_hash = " . (crc32($settings->get_config('board_email')) . strlen($settings->get_config('board_email'))) . ", username_clean = '" . $db->sql_escape(utf8_clean_string($settings->get_config('admin_name'))) . "'
				WHERE username = 'Admin'",

			"UPDATE {$table_prefix}moderator_cache
				SET username = '" . $db->sql_escape($settings->get_config('admin_name')) . "'
				WHERE username = 'Admin'",

			"UPDATE {$table_prefix}forums
				SET forum_last_poster_name = '" . $db->sql_escape($settings->get_config('admin_name')) . "'
				WHERE forum_last_poster_name = 'Admin'",

			"UPDATE {$table_prefix}topics
				SET topic_first_poster_name = '" . $db->sql_escape($settings->get_config('admin_name')) . "', topic_last_poster_name = '" . $db->sql_escape($settings->get_config('admin_name')) . "'
				WHERE topic_first_poster_name = 'Admin'
					OR topic_last_poster_name = 'Admin'",

			"UPDATE {$table_prefix}users
				SET user_regdate = $current_time",

			"UPDATE {$table_prefix}posts
				SET post_time = $current_time, poster_ip = '" . $db->sql_escape($user_ip) . "'",

			"UPDATE {$table_prefix}topics
				SET topic_time = $current_time, topic_last_post_time = $current_time",

			"UPDATE {$table_prefix}forums
				SET forum_last_post_time = $current_time",

			"UPDATE {$table_prefix}config
				SET config_value = '" . $db->sql_escape($settings->get_config('site_name')) . "'
				WHERE config_name = 'sitename'",

			"UPDATE {$table_prefix}config
				SET config_value = '" . $db->sql_escape($settings->get_config('site_desc')) . "'
				WHERE config_name = 'site_desc'",

			// Recompile stale style components needs to be on. This is a testing board.
			"UPDATE {$table_prefix}config
				SET config_value = '1'
				WHERE config_name = 'load_tplcompile'",
		);

		foreach ($sql_ary as $sql)
		{
			if (!$db->sql_query($sql))
			{
				$error = $db->sql_error();
				trigger_error($error['message']);
			}
		}

		// main sql is done, let's get a fresh $config array
		$sql = 'SELECT *
			FROM ' . CONFIG_TABLE;
		$result = $db->sql_query($sql);

		$config = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$config[$row['config_name']] = $row['config_value'];
		}
		$db->sql_freeresult($result);

		// Set other optional config data.
		// Have it here to not having to do a query for each key
		// to see if it is a update or insert.
		if (!empty($other_config))
		{
			$other_config_ary = explode("\n", $other_config);

			foreach ($other_config_ary as $config_row)
			{
				// First check if this is a comment.
				if (strpos($config_row, '#') === 0)
				{
					continue;
				}

				$row_ary = explode(';', $config_row);

				if (empty($row_ary[0]) || empty($row_ary[1]))
				{
					continue;
				}

				$config_name	= $db->sql_escape(trim($row_ary[0]));
				$config_value	= $db->sql_escape(trim($row_ary[1]));
				$is_dynamic		= (!empty($row_ary[2])) ? 1 : 0;

				$sql_ary = array(
					'config_name'	=> $config_name,
					'config_value'	=> $config_value,
					'is_dynamic'	=> $is_dynamic,
				);

				if (array_key_exists($config_name, $config))
				{
					$sql = "UPDATE {$table_prefix}config
						SET " . $db->sql_build_array('UPDATE', $sql_ary) . "
						WHERE config_name = '$config_name'";

					if (!$db->sql_query($sql))
					{
						$error = $db->sql_error();
						trigger_error($error['message']);
					}
				}
				else
				{
					$sql = "INSERT INTO {$table_prefix}config " . $db->sql_build_array('INSERT', $sql_ary);

					if (!$db->sql_query($sql))
					{
						$error = $db->sql_error();
						trigger_error($error['message']);
					}
				}

				// Update the config array.
				$config[$config_name] = $config_value;
			}

			unset($other_config, $other_config_ary);
		}

		// no templates though :P
		$config['load_tplcompile'] = '1';

		// build search index
		include_once($phpbb_root_path . 'includes/search/fulltext_native.' . $phpEx);

		$search = new fulltext_native($error = false);

		$sql = 'SELECT post_id, post_subject, post_text, poster_id, forum_id
			FROM ' . POSTS_TABLE;
		$result = $db->sql_query($sql);

		while ($row = $db->sql_fetchrow($result))
		{
			$search->index('post', $row['post_id'], $row['post_text'], $row['post_subject'], $row['poster_id'], $row['forum_id']);
		}
		$db->sql_freeresult($result);

		// extended phpbb install script
		include($phpbb_root_path . 'install/install_install.' . $phpEx);
		include($quickinstall_path . 'includes/install_install_qi.' . $phpEx);

		$install = new install_install_qi($p_master = new p_master_dummy());
		$install->set_data(array(
			'dbms'				=> $dbms,
			'dbhost'			=> $settings->get_config('dbhost'),
			'dbport'			=> $settings->get_config('dbport'),
			'dbuser'			=> $settings->get_config('dbuser'),
			'dbpasswd'			=> $settings->get_config('dbpasswd'),
			'dbname'			=> $dbname,
			'table_prefix'		=> $table_prefix,
			'default_lang'		=> $settings->get_config('default_lang'),
			'admin_name'		=> $settings->get_config('admin_name'),
			'admin_pass1'		=> $settings->get_config('admin_pass'),
			'admin_pass2'		=> $settings->get_config('admin_pass'),
			'board_email1'		=> $settings->get_config('board_email'),
			'board_email2'		=> $settings->get_config('board_email'),
			'email_enable'		=> $settings->get_config('email_enable', 0),
			'smtp_delivery'		=> $settings->get_config('smtp_delivery', 0),
			'smtp_host'			=> $settings->get_config('smtp_host'),
			'smtp_auth'			=> $settings->get_config('smtp_auth'),
			'smtp_user'			=> $settings->get_config('smtp_user'),
			'smtp_pass'			=> $settings->get_config('smtp_pass'),
			'cookie_secure'		=> $settings->get_config('cookie_secure'),
			'server_protocol'	=> $settings->get_server_protocol(),
			'server_name'		=> $settings->get_config('server_name'),
			'server_port'		=> $settings->get_config('server_port'),
			'script_path'		=> $script_path,
		));
		$install->add_modules(false, false);
		$install->add_language(false, false);
		$install->add_bots(false, false);

		// now automod (easymod)
		if ($automod)
		{
			include($quickinstall_path . 'includes/functions_install_automod.' . $phpEx);
			automod_installer::install_automod($board_dir, $make_writable);
		}

		if ($dbms == 'firebird')
		{
			// copy the temp db over
			file_functions::copy_file($settings->get_cache_dir() . $settings->get_config('db_prefix') . $dbname, $board_dir . $settings->get_config('db_prefix') . $dbname);
			$db->sql_select_db($board_dir . $settings->get_config('db_prefix') . $dbname);
		}

		// clean up
		file_functions::delete_files($board_dir, array('Thumbs.db', 'DS_Store', 'CVS', '.svn', '.git'));

		// remove install dir, develop and umil
		file_functions::delete_dir($board_dir . 'install/');
		file_functions::delete_dir($board_dir . 'develop/');
		file_functions::delete_dir($board_dir . 'umil/');

		// copy extra user added files
		file_functions::copy_dir($quickinstall_path . 'sources/extra/', $board_dir);

		// Install Subsilver2
		if ($subsilver)
		{
			if (!class_exists('bitfield'))
			{
				include($phpbb_root_path . 'includes/functions_content.' . $phpEx);
			}
			include($phpbb_root_path . 'includes/acp/acp_styles.' . $phpEx);
			$acp_styles = new acp_styles();
			$acp_styles->main(0, '');

			// Mostly copied from includes/acp/acp_styles.php
			$reqd_template = $reqd_theme = $reqd_imageset = false;
			$error = $installcfg = $style_row = array();
			$element_ary = array('template' => STYLES_TEMPLATE_TABLE, 'theme' => STYLES_THEME_TABLE, 'imageset' => STYLES_IMAGESET_TABLE);

			$install_path = 'subsilver2';
			$root_path = $phpbb_root_path . 'styles/subsilver2/';
			$cfg_file = $root_path . 'style.cfg';
			$installcfg = parse_cfg_file($cfg_file);

			if (!sizeof($installcfg))
			{
				continue;
			}

			$name		= $installcfg['name'];
			$copyright	= $installcfg['copyright'];
			$version	= $installcfg['version'];

			$style_row = array(
				'style_id'			=> 0,
				'template_id'		=> 0,
				'theme_id'			=> 0,
				'imageset_id'		=> 0,
				'style_name'		=> $installcfg['name'],
				'template_name'		=> $installcfg['name'],
				'theme_name'		=> $installcfg['name'],
				'imageset_name'		=> $installcfg['name'],
				'template_copyright'	=> $installcfg['copyright'],
				'theme_copyright'	=> $installcfg['copyright'],
				'imageset_copyright'	=> $installcfg['copyright'],
				'style_copyright'	=> $installcfg['copyright'],
				'store_db'			=> 0,
				'style_active'		=> 1,
				'style_default'		=> ($subsilver == 2) ? 1 : 0,
			);

			$acp_styles->install_style($error, 'install', $root_path, $style_row['style_id'], $style_row['style_name'], $install_path, $style_row['style_copyright'], $style_row['style_active'], $style_row['style_default'], $style_row);

			unset($error);
		}

		// Add some random users and posts.
		if ($populate)
		{
			include($quickinstall_path . 'includes/functions_populate.' . $phpEx);
			new populate($pop_data);
		}

		// add log entry :D
		$user->ip = &$user_ip;
		add_log('admin', 'LOG_INSTALL_INSTALLED_QI', QI_VERSION);

		// purge cache
		$cache->purge();

		// Make all files world writable.
		if ($make_writable)
		{
			file_functions::make_writable($board_dir);
		}

		// Grant additional permissions
		if ($grant_permissions)
		{
			file_functions::grant_permissions($board_dir, $grant_permissions);
		}

		// if he wants to be redirected, redirect him
		if (empty($alt_env) && $redirect)
		{
			// Log him in first.
			$user->session_begin();
			$auth->login($settings->get_config('admin_name'), $settings->get_config('admin_pass'), false, true, true);
			qi::redirect($board_url);
		}
		else if ($redirect)
		{
			// We are redirecting to a alt environment.
			// We don't know what have been changed there so we can't login without maybe throwing a error.
			// Just redirect without logging in.
			qi::redirect($board_url);
		}

		// On succces just return to main page.
		qi::redirect('index.' . $phpEx);
	}
}
