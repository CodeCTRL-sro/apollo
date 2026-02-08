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
     * @var EntityManagerInterface
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
     * @var string
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
     * ApolloContainer constructor.
     * @param EntityManagerInterface|null $entityManager
     * @param Config $config
     * @param Auth $auth
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
     * @return bool|object
     */
    public function getSessionUser()
    {
        $user = false;
        switch ($this->auth_method) {
            case Auth::Session:
                if (!empty($_SESSION[$this->session_key])) {
                    $sessionRepository = $this->entityManager->getRepository($this->config->get(array('Session', 'entity', 'session')));
                    $session = $sessionRepository->findOneBy(array($this->config->get(array('Session', 'entity', 'session_key'), 'userid') => $_SESSION[$this->session_key], 'sessionid' => session_id()));
                    if ($session) {
                        $getter = "get" . ucfirst($this->config->get(array('Session', 'entity', 'session_key'), 'userid'));
                        $user = $session->$getter();
                    }
                }
            case Auth::JWT:
                if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                    if (preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
                        $jwt = $matches[1];
                        if ($jwt) {
                            $userByJWT = $this->auth->getUserByJWT($jwt);
                            if (is_object($userByJWT)) {
                                $user = $userByJWT;
                            }
                        }
                    }
                }
            case Auth::Cookie:
                if (isset($_COOKIE["auth_token"])) {
                    $userByJWT = $this->auth->getUserByJWT($_COOKIE["auth_token"]);
                    if (is_object($userByJWT)) {
                        $user = $userByJWT;
                    } else {
                        setcookie('auth_token', null, time() - 3600, secure: true, httponly: true);
                    }
                }
        }
        return $user;
    }

    /**
     * @return EntityManagerInterface
     */
    public function getEntitymanager()
    {
        return $this->entityManager;
    }

    /**
     * @return string
     */
    public function getDefaultUrl()
    {
        $url = '';
        return $url;
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
     * @param $url
     * @return string
     */
    public function getRealUrl($url)
    {
        $basepath = rtrim($this->basepath, '/');
        return $url != null ? implode('/', array($basepath, ltrim($url, '/'))) : $basepath;
    }
}
