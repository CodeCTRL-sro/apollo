<?php


namespace Metapp\Apollo\Core;

use Doctrine\ORM\EntityManagerInterface;
use Metapp\Apollo\Core\Config\Config;
use Metapp\Apollo\Core\Services\ServiceManager;
use Metapp\Apollo\Database\Redis\RedisClient;
use Metapp\Apollo\Security\Auth\Auth;
use Metapp\Apollo\Utility\Helper\Helper;
use Metapp\Apollo\Utility\Logger\Interfaces\LoggerHelperInterface;
use Metapp\Apollo\Utility\Logger\Traits\LoggerHelperTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class ApolloContainer implements LoggerHelperInterface
{
    use LoggerHelperTrait;

    /**
     * @var
     */
    protected static string $NAME;
    /**
     * @var
     */
    protected static string $URL;

    /**
     * @var array
     */
    protected static array $permissions = array();
    
    /**
     * @var Config
     */
    protected Config $config;
    
    /**
     * @var \Twig\Environment
     */
    protected Environment $twig;
    
    /**
     * @var EntityManagerInterface
     */
    protected ?EntityManagerInterface $entityManager;
    
    /**
     * @var Auth
     */
    protected Auth $auth;
    
    /**
     * @var Helper
     */
    protected Helper $helper;
    
    /**
     * @var array
     */
    private array $hooks = array();

    /**
     * @var RedisClient|null
     */
    protected ?RedisClient $redis;

    /**
     * @var ServiceManager|null
     */
    protected ?ServiceManager $serviceManager;

    /**
     * ApolloContainer constructor.
     * @param Config $config
     * @param Environment $twig
     * @param Helper $helper
     * @param Auth $auth
     * @param EntityManagerInterface|null $entityManager
     * @param LoggerInterface|null $logger
     * @param \Redis|null $redisInstance
     * @param ServiceManager|null $serviceManager
     */
    public function __construct(Config $config, Environment $twig, Helper $helper, Auth $auth, EntityManagerInterface $entityManager = null, LoggerInterface $logger = null, \Redis $redisInstance = null, ServiceManager $serviceManager = null)
    {
        $this->config = $config->fromDimension(array('route','modules'));
        $this->twig = $twig;
        $this->entityManager = $entityManager;
        $this->auth = $auth;
        $this->helper = $helper;
        $this->redis = new RedisClient($redisInstance, $logger);
        $this->serviceManager = $serviceManager;
        $this->setLogDebug((bool)$this->config->get('debug', false));
        if ($logger) {
            $this->setLogger($logger);
        }
        try {
            $cn = (new \ReflectionClass($this))->getShortName();
            $this->config->setBase($cn);
        } catch (\ReflectionException $e) {
            $this->error('ReflectionClass', array($e->getMessage()));
        }
    }

    protected function resetEntityManager(): ?EntityManagerInterface
    {
        if ($this->entityManager && !$this->entityManager->isOpen() && $this->serviceManager) {
            $this->entityManager = $this->serviceManager->getFresh(EntityManagerInterface::class);
            $this->info('EntityManager was reset due to closed state');
        }
        return $this->entityManager;
    }

    /**
     * @return array
     */
    public static function getPermissionList()
    {
        return static::$permissions;
    }

    /**
     * @return string
     */
    public static function getNAME()
    {
        return static::$NAME;
    }

    /**
     * @return string
     */
    public static function getURL()
    {
        return static::$URL;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $args
     * @return ResponseInterface
     */
    public function notImplemented(ServerRequestInterface $request, ResponseInterface $response, array $args)
    {
        $params = array(
            'block' => array(
                'contents' => array(
                    $this->dump($args, 'args', 0, true),
                    $this->dump($request->getQueryParams(), 'params', 0, true),
                ),
            ),
        );

        return $this->twigErrorResponse($response, 501, $params);
    }

    /**
     * @param $name
     * @return bool
     */
    protected function hasHook($name)
    {
        return isset($this->hooks[$name]);
    }

    /**
     * @return array
     */
    protected function getHooks()
    {
        return $this->hooks;
    }

    /**
     * @param $name
     * @param $callable
     * @return ApolloContainer
     */
    protected function addHook($name, $callable)
    {
        if (!isset($this->hooks[$name])) {
            if (is_callable($callable)) {
                $this->hooks[$name] = $callable;
            }
        }
        return $this;
    }

    /**
     * @param $name
     * @param $callable
     * @return ApolloContainer
     */
    protected function setHook($name, $callable)
    {
        if (isset($this->hooks[$name])) {
            if (is_callable($callable)) {
                $this->hooks[$name] = $callable;
            }
        }
        return $this;
    }

    /**
     * @param $name
     * @return ApolloContainer
     */
    protected function delHook($name)
    {
        if (isset($this->hooks[$name])) {
            unset($this->hooks[$name]);
        }
        return $this;
    }

    /**
     * @param array $hooks
     * @return ApolloContainer
     */
    protected function setHooks(array $hooks)
    {
        $this->hooks = array();
        if (!empty($hooks)) {
            foreach ($hooks as $name => $callable) {
                $this->addHook($name, $callable);
            }
        }
        return $this;
    }

    /**
     * @return mixed
     */
    protected function callHook()
    {
        if (!empty($this->hooks)) {
            $args = func_get_args();
            $name = array_shift($args);
            if ($name && isset($this->hooks[$name])) {
                if (is_callable($this->hooks[$name])) {
                    return call_user_func_array($this->hooks[$name], $args);
                }
            }
        }
        return null;
    }

    /**
     * @return EntityManagerInterface
     */
    public function getEntityManager(): EntityManagerInterface
    {
        if ($this->entityManager && !$this->entityManager->isOpen()) {
            $this->resetEntityManager();
        }
        return $this->entityManager;
    }

    /**
     * @return Auth
     */
    public function getAuth()
    {
        return $this->auth;
    }

    /**
     * @return Environment
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param ResponseInterface $response
     * @param $code
     * @param array $params
     * @return ResponseInterface|static
     */
    protected function twigErrorResponse(ResponseInterface $response, $code, $params = array())
    {
        $response = $response->withStatus($code);

        if (!isset($params['title'])) {
            $params['title'] = $response->getStatusCode();
        }
        if (!isset($params['block']['title'])) {
            $params['block']['title'] = $response->getReasonPhrase();
        }

        /** @var Environment $twig */
        $twig = $this->twig;
        try {
            $response->getBody()->write($twig->render('errors.html.twig', $params));
        } catch (LoaderError $e) {
        } catch (RuntimeError $e) {
        } catch (SyntaxError $e) {
        }
        return $response;
    }

    /**
     * @param $var
     * @param string $name
     * @param int $mode
     * @param bool $return
     * @return string | null
     */
    function dump($var, $name = '', $mode = 0, $return = false)
    {
        switch ($mode) {
            case 1: case 'r': $dump = print_r($var, true); break;
            case 2: case 'o':
            ob_start();
            var_dump($var);
            $dump = ob_get_clean();
            break;
            case 3: $dump = var_export(json_encode($var, JSON_PRETTY_PRINT), true); break;
            case 0: default: $dump = var_export($var, true); break;
        }
        $ret = '<pre>'.highlight_string("<?php\n".($name ? "\${$name} =\n" : '')."{$dump}\n?>", true).'</pre>';
        if ($return) {
            return $ret;
        }
        echo $ret;
        return null;
    }
}
