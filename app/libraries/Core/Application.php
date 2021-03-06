<?php
/**
 * @author Uhon Liu http://phalconcmf.com <futustar@qq.com>
 */

namespace Core;

use Phalcon\Di\FactoryDefault;
use Core\Cache\Cache;
use Core\Models\CoreOptions;
use Core\Models\CorePhpLogs;
use Phalcon\Mvc\Application as PApplication;

class Application extends PApplication
{
    use ApplicationInit;

    /**
     * Cache modules key
     */
    const APPLICATION_CACHE_MODULES = 'APPLICATION_CACHE_MODULES';

    /**
     * @var mixed
     */
    protected $config;

    /**
     * Instance construct
     */
    public function __construct()
    {
        /**
         * Create default DI
         */
        $this->di = new FactoryDefault();
        $this->config = Factory::config();
        if($this->config->website->baseUri == '') {
            if($_SERVER['SERVER_PORT'] != '443') {
                $this->config->website->baseUri = 'http://' . $_SERVER['HTTP_HOST'] . str_replace(['/public/index.php', '/index.php'], '', $_SERVER['SCRIPT_NAME']);
            } else {
                $this->config->website->baseUri = 'https:// ' . $_SERVER['HTTP_HOST'] . str_replace(['/public/index.php', '/index.php'], '', $_SERVER['SCRIPT_NAME']);
            }

        }

        $this->di->set('config', $this->config);
        /**
         * @define bool DEBUG
         */
        define('DEBUG', $this->config->debug);

        /**
         * @define string BASE_URI
         */
        define('BASE_URI', $this->config->website->baseUri);
        include APP_PATH . '/libraries/Core/Utilities/Functions.php';

        parent::__construct($this->di);
    }

    /**
     * Run application
     *
     * @return bool|\Phalcon\Http\ResponseInterface
     */
    public function run()
    {
        $this->_initLoader($this->_dependencyInjector);

        $this->_initServices($this->_dependencyInjector, $this->config);

        $this->_initModule();

        $handle = $this->handle();
        if($this->config->logError) {
            $error = error_get_last();
            if($error) {
                $logKey = md5(implode('|', $error));
                /**
                 * @var $corePhpLog CorePhpLogs
                 */
                $corePhpLog = CorePhpLogs::findFirst([
                    'conditions' => 'log_key = ?0',
                    'bind' => [$logKey]
                ]);

                if($corePhpLog) {
                    $corePhpLog->status = 0;
                } else {
                    $corePhpLog = new CorePhpLogs();
                    $corePhpLog->assign([
                        'log_key' => $logKey,
                        'type' => $error['type'],
                        'message' => $error['message'],
                        'file' => $error['file'],
                        'line' => $error['line'],
                        'status' => 0
                    ]);
                }
                $corePhpLog->save();
            }
        }

        return $handle;
    }

    /**
     * Auto load module from database
     */
    public function _initModule()
    {
        // Create new cache
        $cache = Cache::getInstance(APPLICATION);

        CoreOptions::initOrUpdateCacheOptions();

        // Load module
        $registerModules = $cache->get(self::APPLICATION_CACHE_MODULES);
        if($registerModules === null) {
            /**
             * @var \Phalcon\Db\Adapter\Pdo\Postgresql $db
             */
            $db = $this->getDI()->get('db');
            $query = 'SELECT base_name, class_name, path FROM core_modules WHERE published = 1';
            $modules = $db->fetchAll($query);
            $registerModules = [];
            foreach($modules as $module) {
                $registerModules[$module['base_name']] = [
                    'className' => $module['class_name'],
                    'path' => APP_PATH . '/modules' . $module['path']
                ];
            }
            $cache->save(self::APPLICATION_CACHE_MODULES, $registerModules);
        }
        $this->registerModules($registerModules);
    }
}