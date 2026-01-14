<?php

declare(strict_types=1);

namespace Metapp\Apollo;

use Metapp\Apollo\Config\Config;
use GuzzleHttp\Psr7\ServerRequest;
use Metapp\Apollo\Factory\Factory;
use Metapp\Apollo\Logger\ErrorLogger;
use Metapp\Apollo\Logger\Logger;
use Metapp\Apollo\Utils\ServiceProvider;

class Apollo
{
    /** @var array $configModules */
    private $configModules = array();

    /** @var array $excludedConfigDirs */
    private $excludedConfigDirs = array();

    /** @var bool $dynamicRouteLoad */
    private $dynamicRouteLoad = false;

    /** @var $config */
    private $config;

    /** @var $container */
    private $container;

    /** @var string $baseDir */
    private $baseDir;

    /** @var string $homeDir */
    private $homeDir = "";

    /** @var int $maxLoggerFiles */
    private $maxLoggerFiles = 7;

    /** @var bool $allowErrorReporting */
    private $allowErrorReporting = false;

    private function initErrorHandler()
    {
        $error_logger = new ErrorLogger(new Logger('PHP', $this->maxLoggerFiles));
        set_error_handler(array($error_logger, 'customErrorHandler'));
    }

    private function initContainers(Config $config)
    {
        $this->container = new \League\Container\Container();
        $request = ServerRequest::fromGlobals();
        $serviceProvider = new ServiceProvider($config, $request);
        $this->container->addServiceProvider($serviceProvider);
        return $this->container;
    }

    /**
     * @param array $configModules
     */
    public function setConfigModules($configModules = array())
    {
        $this->configModules = $configModules;
    }

    public function allowErrorReporting()
    {
        $this->allowErrorReporting = true;
    }

    public function setDynamicRouteLoad($load = false)
    {
        $this->dynamicRouteLoad = $load;
    }

    public function setExcludedConfigDirs($dirs)
    {
        $this->excludedConfigDirs = $dirs;
    }

    /**
     * @return string
     */
    public function getBaseDir()
    {
        return $this->baseDir;
    }

    /**
     * @param string $baseDir
     * @return Apollo
     */
    public function setBaseDir($baseDir)
    {
        $this->baseDir = $baseDir;
        define("BASE_DIR",$this->baseDir);
        return $this;
    }

    /**
     * @return string
     */
    public function getHomeDir()
    {
        return $this->homeDir;
    }

    /**
     * @param string $homeDir
     * @return Apollo
     */
    public function setHomeDir($homeDir)
    {
        $this->homeDir = $homeDir;
        define("HOME_DIR",$this->homeDir);
        return $this;
    }

    /**
     * @return int
     */
    public function getMaxLoggerFiles()
    {
        return $this->maxLoggerFiles;
    }

    /**
     * @param int $maxLoggerFiles
     * @return Apollo
     */
    public function setMaxLoggerFiles($maxLoggerFiles)
    {
        $this->maxLoggerFiles = $maxLoggerFiles;
        return $this;
    }

    private function initConfigs(){
        $configPath = $this->baseDir."/config/";
        Factory::setConfigPath($configPath);
        $configModules = $this->configModules;
        if(empty($configModules)){
            $configModules = $this->buildRoutes(array_diff(scandir($configPath), array_merge(array('.', '..','cli-config.php','translations'),$this->excludedConfigDirs)));
        }
        $this->config = Factory::fromNames($configModules, true);
    }

    public function run()
    {
        if($this->allowErrorReporting){
            ini_set('display_errors','true');
            error_reporting(E_ALL);
        }else{
            ini_set("display_errors", 'false');
            error_reporting(0);
        }
        $this->initConfigs();
        $this->initErrorHandler();
        $modules_config = $this->config->get(array('route', 'modules'));
        foreach ($modules_config as $module) {
            if (is_array($module) && !empty($module['paths'])) {
                if (count($module['paths']) == 1 && array_key_exists('/', $module['paths'])) {
                    $cfg = $module['paths'];
                } else {
                    $cfg = array('/' => array('paths' => $module['paths']));
                }
                $this->config->merge(array('routing' => array('paths' => $cfg)));
            }
        }
        $container = $this->initContainers($this->config);
        $core = new ApolloKernel($container);
        return $core->go();
    }

    private function buildRoutes($array = array()){
        $moduleFolders = array();
        foreach (new \DirectoryIterator($_SERVER["DOCUMENT_ROOT"]."/modules") as $dir) {
            if ($dir->isDot()) continue;
            if ($dir->isDir()) {
                $moduleFolders[] = $dir->current()->getFilename();
            }
        }
        if($this->dynamicRouteLoad) {
            $findUrlBasepath = explode("/", $_SERVER['REQUEST_URI'])[1];
            foreach($array as $itemKey => $item){
                if(in_array($findUrlBasepath,$moduleFolders)){
                    if(strpos($item,'rout') !== false) {
                        if (strpos($item, $findUrlBasepath . '_rout') === false) {
                            unset($array[$itemKey]);
                        }
                    }
                }else{
                    if(strpos($item,'_rout') !== false){
                        unset($array[$itemKey]);
                    }
                }
            }
        }

        return array_map(function($file){
            return explode(".php",$file)[0];
        },$array);
    }
}