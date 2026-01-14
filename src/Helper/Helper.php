<?php

namespace Metapp\Apollo\Helper;


use Metapp\Apollo\Auth\Auth;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Metapp\Apollo\Config\Config;
use Generator;
use Metapp\Apollo\Logger\Interfaces\LoggerHelperInterface;
use Metapp\Apollo\Logger\Traits\LoggerHelperTrait;
use Metapp\Apollo\modules\Session\Entity\Session;
use Metapp\Apollo\modules\Session\Entity\SessionRepository;

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
	protected $session_key = 'user';

	/**
	 * @var bool
	 */
	protected $session_destroy = true;

	/**
	 * ApolloContainer constructor.
	 * @param EntityManagerInterface $entityManager
	 * @param Config $config
	 * @param Auth $auth
	 * @param LoggerInterface|null $logger
	 */
	public function __construct(EntityManagerInterface $entityManager, Config $config, Auth $auth, LoggerInterface $logger = null)
	{
		$this->entityManager = $entityManager;
		$this->auth = $auth;
		$this->basepath = $config->get(array('routing', 'basepath'), '/');
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
	public function getSessionUser($apolloContainer = null)
	{
		if (!empty($_SESSION[$this->session_key])) {
			$tmpUser = false;
			/** @var SessionRepository $sessionRepository */
			$sessionRepository = $this->entityManager->getRepository($this->config->get(array('Session', 'entity', 'session'), 'Session:Session'));
			/** @var Session $session */
			$session = $sessionRepository->findOneBy(array($this->config->get(array('Session', 'entity', 'session_key'), 'userid') => $_SESSION[$this->session_key], 'sessionid' => session_id()));
			if ($session) {
				$getter = "get" . ucfirst($this->config->get(array('Session', 'entity', 'session_key'), 'userid'));
				$user = $session->$getter();
				if ($user && method_exists($user, 'setLastUserInteraction')) {
					$user->setLastUserInteraction(date_create());
					$this->entityManager->persist($user);
					$this->entityManager->flush();
				}
				$tmpUser = $user;
			}

			$logoutProxyUser = false;
			if ($apolloContainer != null) {
				$proxyResponse = \Laptapir\helpers\Auth::manageProxyAuth($apolloContainer, $tmpUser);
				if ($proxyResponse["user"]) {
					return $proxyResponse["user"];
				} else {
					if ($proxyResponse["sessionChanged"]) {
						return false;
					}
				}
			}
			return $tmpUser;
		} else {
			if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
				if (preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
					$jwt = $matches[1];
					if ($jwt) {
						$user = $this->auth->getUserByJWT($jwt);
						if (is_object($user)) {
							if ($user && method_exists($user, 'setLastUserInteraction')) {
								$user->setLastUserInteraction(date_create());
								$this->entityManager->persist($user);
								$this->entityManager->flush();
							}
							return $user;
						}
					}
				}
			}
		}
		if ($apolloContainer != null) {
			if (class_exists('\Laptapir\helpers\Auth') && method_exists('\Laptapir\helpers\Auth', 'manageProxyAuth')) {
				$proxyResponse = \Laptapir\helpers\Auth::manageProxyAuth($apolloContainer);
				if (!empty($proxyResponse['user'])) {
					return $proxyResponse['user'];
				}
			}
		}
		return false;
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
     * @return string
     */
    public function getAdminBasepath()
    {
        return '/admin/' . ($_COOKIE["default_language"] ?? 'hu');
    }

	/**
	 * @param $url
	 * @return string
	 */
	public function getRealUrl($url)
	{
		$basepath = rtrim($this->basepath, '/');
		return implode('/', array($basepath, ltrim($url, '/')));
	}
}
