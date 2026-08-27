<?php

namespace CodeCTRL\Apollo\Utility\Helper;

use Doctrine\ORM\EntityManagerInterface;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Security\Auth\Auth;
use CodeCTRL\Apollo\Utility\Logger\Interfaces\LoggerHelperInterface;
use CodeCTRL\Apollo\Utility\Logger\Traits\LoggerHelperTrait;
use Psr\Log\LoggerInterface;

class Helper implements LoggerHelperInterface
{
    use LoggerHelperTrait;

    /**
     * @var EntityManagerInterface|null
     */
    protected $entityManager;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Auth
     */
    protected $auth;

    /**
     * @var string
     */
    protected $basepath;

    /**
     * @var string|null
     */
    protected $auth_method;

    /**
     * @var string
     */
    protected $session_key = 'user';

    /**
     * @var bool
     */
    protected $session_destroy = true;

    /**
     * Resolved user for this request, or false when anonymous.
     *
     * @var object|false
     */
    private $sessionUser = false;

    /**
     * Whether getSessionUser() has already run for this request. Kept separate from
     * $sessionUser so that an anonymous result is cached too — without it every caller
     * would repeat the same lookup that just failed.
     *
     * @var bool
     */
    private bool $sessionUserResolved = false;

    /**
     * @param Config $config
     * @param Auth $auth
     * @param EntityManagerInterface|null $entityManager
     * @param LoggerInterface|null $logger
     */
    public function __construct(Config $config, Auth $auth, EntityManagerInterface $entityManager = null, LoggerInterface $logger = null)
    {
        $this->entityManager = $entityManager;
        $this->auth = $auth;
        $this->basepath = $config->get(array('routing', 'basepath'), '/');
        $this->auth_method = $config->get(array('routing', 'auth_method'), null);
        $this->config = $config->fromDimension(array('route', 'modules'));
        $this->setLogDebug($this->config->get('debug', false));
        if ($logger) {
            $this->setLogger($logger);
        }
        $this->session_key = $this->config->get(array('Session', 'session_key'), 'user');
        $this->session_destroy = $this->config->get(array('Session', 'session_destroy'), true);
    }

    /**
     * The authenticated user for this request, or false.
     *
     * Before 3.3.0 this was a switch whose cases had no break, so a Session configured
     * application also ran the JWT and Cookie branches. That cost two extra database
     * round trips per call, and — worse — an auth_token cookie took precedence over the
     * session it was supposed to be an alternative to. Exactly one strategy runs now.
     *
     * The result is memoised for the request: several middlewares ask for the same user,
     * and each answer used to be a fresh query.
     *
     * @return object|false
     */
    public function getSessionUser()
    {
        if ($this->sessionUserResolved) {
            return $this->sessionUser;
        }

        $this->sessionUser = match ($this->auth_method) {
            Auth::Session => $this->userFromSession(),
            Auth::JWT => $this->userFromBearerToken(),
            Auth::Cookie => $this->userFromCookie(),
            default => false,
        };
        $this->sessionUserResolved = true;

        return $this->sessionUser;
    }

    /**
     * Drop the memoised user, e.g. right after a login or logout changed who is acting.
     *
     * @return $this
     */
    public function forgetSessionUser()
    {
        $this->sessionUser = false;
        $this->sessionUserResolved = false;

        return $this;
    }

    /**
     * @return object|false
     */
    protected function userFromSession()
    {
        if (!$this->entityManager instanceof EntityManagerInterface) {
            return false;
        }
        if (empty($_SESSION[$this->session_key])) {
            return false;
        }

        $sessionEntity = $this->config->get(array('Session', 'entity', 'session'));
        if (empty($sessionEntity)) {
            return false;
        }

        $sessionKey = $this->config->get(array('Session', 'entity', 'session_key'), 'userid');
        $session = $this->entityManager->getRepository($sessionEntity)->findOneBy(array(
            $sessionKey => $_SESSION[$this->session_key],
            'sessionid' => session_id(),
        ));

        if (!$session) {
            return false;
        }

        $getter = 'get' . ucfirst($sessionKey);
        if (!method_exists($session, $getter)) {
            return false;
        }

        $user = $session->$getter();

        return is_object($user) ? $user : false;
    }

    /**
     * @return object|false
     */
    protected function userFromBearerToken()
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' || !preg_match('/Bearer\s(\S+)/', $header, $matches)) {
            return false;
        }

        $user = $this->auth->getUserByJWT($matches[1]);

        return is_object($user) ? $user : false;
    }

    /**
     * @return object|false
     */
    protected function userFromCookie()
    {
        if (empty($_COOKIE['auth_token'])) {
            return false;
        }

        $user = $this->auth->getUserByJWT($_COOKIE['auth_token']);
        if (is_object($user)) {
            return $user;
        }

        setcookie('auth_token', '', time() - 3600, secure: true, httponly: true);

        return false;
    }

    /**
     * @return EntityManagerInterface|null
     */
    public function getEntitymanager()
    {
        return $this->entityManager;
    }

    /**
     * @return string
     * @deprecated 3.3.0 Always returned an empty string and has no callers in the
     *             framework. Override it in your application or stop calling it;
     *             it will be removed in 4.0.
     */
    public function getDefaultUrl()
    {
        return '';
    }

    /**
     * @return string
     */
    public function getSessionKey()
    {
        return $this->session_key;
    }

    /**
     * @return bool
     */
    public function isSessionDestroy()
    {
        return $this->session_destroy;
    }

    /**
     * @return string
     */
    public function getBasepath()
    {
        return $this->basepath;
    }

    /**
     * @param string|null $url
     * @return string
     */
    public function getRealUrl($url)
    {
        $basepath = rtrim($this->basepath, '/');

        return $url != null ? implode('/', array($basepath, ltrim($url, '/'))) : $basepath;
    }
}
