<?php
/**
 *
 * Copyright (C) 2015-2016 FeatherBB
 * based on code by (C) 2008-2015 FluxBB
 * and Rickard Andersson (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * $app = new \Slim\Slim();
 * $app->add(new \Slim\Extras\Middleware\FeatherBBLoader(array $config));
 *
 */

namespace FeatherBB\Middleware;

use FeatherBB\Controller\Install;
use FeatherBB\Core\Database as DB;
use FeatherBB\Core\Email;
use FeatherBB\Core\Hooks;
use FeatherBB\Core\Parser;
use FeatherBB\Core\Plugin as PluginManager;
use FeatherBB\Core\Url;
use FeatherBB\Core\Utils;
use FeatherBB\Core\View;

class Core
{
    protected $forum_env,
              $forum_settings;
    protected $headers = array(
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
        'Content-type' => 'text/html',
        'X-Frame-Options' => 'deny');

    public function __construct(array $data)
    {
        // Handle empty values in data
        $data = array_merge(array('config_file' => 'featherbb/config.php',
                                  'cache_dir' => 'cache/',
                                  'debug'   => false), $data);
        // Define some core variables
        $this->forum_env['FEATHER_ROOT'] = realpath(dirname(__FILE__).'/../../').'/';
        $this->forum_env['FORUM_CACHE_DIR'] = is_writable($this->forum_env['FEATHER_ROOT'].$data['cache_dir']) ? realpath($this->forum_env['FEATHER_ROOT'].$data['cache_dir']).'/' : null;
        $this->forum_env['FORUM_CONFIG_FILE'] = $this->forum_env['FEATHER_ROOT'].$data['config_file'];
        $this->forum_env['FEATHER_DEBUG'] = $this->forum_env['FEATHER_SHOW_QUERIES'] = ($data['debug'] == 'all');
        $this->forum_env['FEATHER_SHOW_INFO'] = ($data['debug'] == 'info' || $data['debug'] == 'all');

        // Populate forum_env
        $this->forum_env = array_merge(self::load_default_forum_env(), $this->forum_env);

        // Load files
        require $this->forum_env['FEATHER_ROOT'].'featherbb/Helpers/utf8/utf8.php';
        require $this->forum_env['FEATHER_ROOT'].'featherbb/Core/gettext/l10n.php';

        // Force POSIX locale (to prevent functions such as strtolower() from messing up UTF-8 strings)
        setlocale(LC_CTYPE, 'C');
    }

    public static function load_default_forum_env()
    {
        return array(
                'FEATHER_ROOT' => '',
                'FORUM_CONFIG_FILE' => 'featherbb/config.php',
                'FORUM_CACHE_DIR' => 'cache/',
                'FORUM_VERSION' => '1.0.0',
                'FORUM_NAME' => 'FeatherBB',
                'FORUM_DB_REVISION' => 21,
                'FORUM_SI_REVISION' => 2,
                'FORUM_PARSER_REVISION' => 2,
                'FEATHER_UNVERIFIED' => 0,
                'FEATHER_ADMIN' => 1,
                'FEATHER_MOD' => 2,
                'FEATHER_GUEST' => 3,
                'FEATHER_MEMBER' => 4,
                'FEATHER_MAX_POSTSIZE' => 32768,
                'FEATHER_SEARCH_MIN_WORD' => 3,
                'FEATHER_SEARCH_MAX_WORD' => 20,
                'FORUM_MAX_COOKIE_SIZE' => 4048,
                'FEATHER_DEBUG' => false,
                'FEATHER_SHOW_QUERIES' => false,
                'FEATHER_SHOW_INFO' => false
                );
    }

    public static function load_default_forum_settings()
    {
        return array(
                // Database
                'db_type' => 'mysqli',
                'db_host' => '',
                'db_name' => '',
                'db_user' => '',
                'db_pass' => '',
                'db_prefix' => '',
                // Cookies
                'cookie_name' => 'feather_cookie',
                'jwt_token' => 'changeme', // MUST BE CHANGED !!!
                'jwt_algorithm' => 'HS512'
                );
    }

    public static function init_db(array $config, $log_queries = false)
    {
        $config['db_prefix'] = (!empty($config['db_prefix'])) ? $config['db_prefix'] : '';
        switch ($config['db_type']) {
            case 'mysql':
                DB::configure('mysql:host='.$config['db_host'].';dbname='.$config['db_name']);
                DB::configure('driver_options', array(\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'));
                break;
            case 'sqlite';
            case 'sqlite3';
                DB::configure('sqlite:./'.$config['db_name']);
                break;
            case 'pgsql':
                DB::configure('pgsql:host='.$config['db_host'].'dbname='.$config['db_name']);
                break;
        }
        DB::configure('username', $config['db_user']);
        DB::configure('password', $config['db_pass']);
        DB::configure('prefix', $config['db_prefix']);
        if ($log_queries) {
            DB::configure('logging', true);
        }
        DB::configure('id_column_overrides', array(
            $config['db_prefix'].'groups' => 'g_id',
        ));
    }

    public static function loadPlugins()
    {
        $manager = new PluginManager();
        $manager->loadPlugins();
    }

    // Headers

    public function set_headers($res)
    {
        foreach ($this->headers as $label => $value) {
            $res = $res->withHeader($label, $value);
        }
        $res = $res->withHeader('X-Powered-By', $this->forum_env['FORUM_NAME']);

        return $res;
    }

    public function __invoke($req, $res, $next)
    {
        // Set headers
        $res = $this->set_headers($res);

        // Block prefetch requests
        if ((isset($this->app->environment['HTTP_X_MOZ'])) && ($this->app->environment['HTTP_X_MOZ'] == 'prefetch')) {
            return $this->app->response->setStatus(403); // Send forbidden header
        }

        // Populate Slim object with forum_env vars
        Container::set('forum_env', $this->forum_env);
        // Load FeatherBB utils class
        Container::set('utils', function ($container) {
            return new Utils();
        });
        // Record start time
        Container::set('start', Utils::get_microtime());
        // Define now var
        Container::set('now', function () {
            return time();
        });
        // Load FeatherBB cache
        Container::set('cache', function ($container) {
            $path = $this->forum_env['FORUM_CACHE_DIR'];
            return new \FeatherBB\Core\Cache(array('name' => 'feather',
                                               'path' => $path,
                                               'extension' => '.cache'));
        });
        // Load FeatherBB permissions
        Container::set('perms', function ($container) {
            return new \FeatherBB\Core\Permissions();
        });
        // Load FeatherBB preferences
        Container::set('prefs', function ($container) {
            return new \FeatherBB\Core\Preferences();
        });
        // Load FeatherBB view
        Container::set('template', function ($container) {
            return new View();
        });
        // Load FeatherBB url class
        Container::set('url', function ($container) {
            return new Url();
        });
        // Load FeatherBB hooks
        Container::set('hooks', function ($container) {
            return new Hooks();
        });
        // Load FeatherBB email class
        Container::set('email', function ($container) {
            return new Email();
        });

        Container::set('parser', function ($container) {
            return new Parser();
        });
        // Set cookies
        Container::set('cookie', function ($container){
            $request = $container->get('request');
            return new \Slim\Http\Cookies($request->getCookieParams());
        });
        Container::set('flash', function($c) {
            return new \Slim\Flash\Messages;
        });

        // This is the very first hook fired
        Container::get('hooks')->fire('core.start');

        if (!is_file(ForumEnv::get('FORUM_CONFIG_FILE'))) {
            // Reset cache
            Container::get('cache')->flush();
            $installer = new \FeatherBB\Controller\Install();
            return $installer->run();
        }

        // Load config from disk
        include ForumEnv::get('FORUM_CONFIG_FILE');
        if (isset($featherbb_config) && is_array($featherbb_config)) {
            $this->forum_settings = array_merge(self::load_default_forum_settings(), $featherbb_config);
        } else {
            $this->app->response->setStatus(500); // Send forbidden header
            return $this->app->response->setBody('Wrong config file format');
        }

        // Init DB and configure Slim
        self::init_db($this->forum_settings, ForumEnv::get('FEATHER_SHOW_INFO'));
        Config::set('displayErrorDetails', ForumEnv::get('FEATHER_DEBUG'));

        if (!Container::get('cache')->isCached('config')) {
            Container::get('cache')->store('config', \FeatherBB\Model\Cache::get_config());
        }

        // Finalize forum_settings array
        $this->forum_settings = array_merge(Container::get('cache')->retrieve('config'), $this->forum_settings);
        Container::set('forum_settings', $this->forum_settings);

        // Set default style and assets
        Container::get('template')->setStyle(ForumSettings::get('o_default_style'));
        Container::get('template')->addAsset('js', 'style/themes/FeatherBB/phone.min.js');

        // Run activated plugins
        self::loadPlugins();

        // Define time formats and add them to the container
        Container::set('forum_time_formats', array(ForumSettings::get('o_time_format'), 'H:i:s', 'H:i', 'g:i:s a', 'g:i a'));
        Container::set('forum_date_formats', array(ForumSettings::get('o_date_format'), 'Y-m-d', 'Y-d-m', 'd-m-Y', 'm-d-Y', 'M j Y', 'jS M Y'));

        // Call FeatherBBAuth middleware
        return $next($req, $res);
    }
}
