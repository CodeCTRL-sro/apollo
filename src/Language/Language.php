<?php
namespace Metapp\Apollo\Language;


use Metapp\Apollo\Auth\Auth;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Metapp\Apollo\ApolloContainer;
use Metapp\Apollo\Config\Config;
use Metapp\Apollo\Helper\Helper;
use Twig\Environment;

class Language extends ApolloContainer
{
    protected $languages;
    protected $default_language;
    protected $lang;
    protected $translate = array();
    protected static $NAME;
    protected static $URLS = array();
    protected $request;

    public function __construct(Config $config, Environment $twig, EntityManagerInterface $entityManager, Helper $helper, ServerRequestInterface $request, Auth $auth, LoggerInterface $logger = null)
    {
        $this->request = $request;
        $this->languages = array();
        foreach (array_diff(scandir($config->get(array('route', 'translator', 'path'), '')),array('.', '..')) as $lang) {
            if (strpos($lang, '.php') !== false) {
                $this->languages[] = str_replace(".php", "", $lang);
            }
        }
        $this->default_language = $config->get(array('route', 'translator', 'default'), 'en');
        $this->lang = self::parseLang($config);
        foreach($this->languages as $lang) {
            $this->translate[$lang] = include($config->get(array('route', 'translator', 'path'), null).'/'.$lang.'.php');
        }
        $twig->addGlobal('__lang', $this->lang);
        $twig->addGlobal('__lang_urls', $this->getUrls());
        $twig->addGlobal('__languages', $this->languages);
        $twig->addGlobal('__global_translations', $this->translate[$this->lang]);
        setcookie('default_language',$this->lang,strtotime('+365 days'));

        parent::__construct($config, $twig, $entityManager, $helper, $auth, $logger);
    }

    /**
     * @param Config $config
     * @return string
     */
    public static function parseLang(Config $config)
    {
        $languages = array();
        foreach (array_diff(scandir($config->get(array('route','translator', 'path'), '')),array('.', '..')) as $lang) {
            $languages[] = str_replace(".php","",$lang);
        }

        if(isset($_SERVER["HTTP_CONTENT_LANGUAGE"])){
            if(!empty($_SERVER["HTTP_CONTENT_LANGUAGE"])){
                if(in_array($_SERVER["HTTP_CONTENT_LANGUAGE"], $languages)) {
                    return $_SERVER["HTTP_CONTENT_LANGUAGE"];
                }
            }
        }

        $params = $_GET;
        if(isset($params["language"])){
            if(in_array($params["request"],$languages)){
                return $params["request"];
            }
            if(in_array($params["language"],$languages)){
                return $params["language"];
            }
        }
        if (array_key_exists('request', $params)) {
            $tmp = explode('/', $params['request']);
            $lng = array_shift($tmp);
            // Handle admin routes - language comes after 'admin/'
            if ($lng === 'admin' && !empty($tmp)) {
                $lng = array_shift($tmp);
            }
            // First check if URL has a valid language
            if (in_array($lng, $languages)) {
                return $lng;
            }
            // For non-API requests, fall back to cookie
            if (strpos($params["request"], 'api/') === false) {
                if(isset($_COOKIE["default_language"]) && in_array($_COOKIE["default_language"], $languages)){
                    return $_COOKIE["default_language"];
                }
            }
            // Fall back to header or default
            $headerLang = (isset($_SERVER["HTTP_CONTENT_LANGUAGE"]) ? $_SERVER["HTTP_CONTENT_LANGUAGE"] : $config->get(array('route','translator','default'), 'en'));
            return !empty($headerLang) && in_array($headerLang, $languages) ? $headerLang : $config->get(array('route','translator','default'), 'en');
        } else {
            return $config->get(array('route','translator','default'), 'en');
        }
    }

    /**
     * @return string
     */
    public static function getURL()
    {
        return static::$URL;
    }

    /**
     * @param $key
     * @param string $lang
     * @return mixed
     */
    public function trans($key, $lang = '')
    {
        if (!$lang) {
            $lang = $this->lang;
        }
        $text = '';
        if (isset($this->translate[$lang][$key])) {
            $text = $this->translate[$lang][$key];
        }else{
            if(isset($this->translate[$this->default_language][$key])) {
                $text = $this->translate[$this->default_language][$key];
            }else{
                $text = '--{'.$key.'}--';
            }
        }
        return $text;
    }

    public function search($term, $lang = '')
    {
        if (!$lang) {
            $lang = $this->lang;
        }
        foreach ($this->translate[$lang] as $txt) {
            if (is_string($txt)) {
                if (mb_stripos($txt, $term) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array
     */
    public function getUrls()
    {
        return static::$URLS;
    }

    /**
     * @param $class
     * @param ServerRequestInterface $request
     * @return self
     */
    protected function loadMultiModule($class, ServerRequestInterface $request = null)
    {
        try {
            $reflector = new ReflectionClass($class);
        } catch (Exception $exception) {
            return null;
        }
        if (in_array(LanguageModulesInterface::class, $reflector->getInterfaceNames())) {
            if (!$request) {
                $request = $this->request;
            }
            $config = new Config(array('route' => $this->config->toArray()));
            return new $class($config, $this->twig, $this->entityManager, $this->helper, $request, $this->logger, true);
        }
        return null;
    }
	
    /**
     * @return array|string|null
     */
    public function getLanguages()
    {
        return $this->languages;
    }

    /**
     * Get URL with language prefix for admin routes
     * @param string $path
     * @return string
     */
    public function getAdminUrl($path)
    {
        return '/admin/' . $this->lang . '/' . ltrim($path, '/');
    }

    /**
     * Get URL with language prefix for web routes
     * @param string $path
     * @return string
     */
    public function getLangUrl($path)
    {
        return '/' . $this->lang . '/' . ltrim($path, '/');
    }
}
