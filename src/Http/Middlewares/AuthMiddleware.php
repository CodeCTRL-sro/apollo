<?php

namespace CodeCTRL\Apollo\Http\Middlewares;

use Doctrine\ORM\EntityManagerInterface;
use League\Route\Http\Exception\UnauthorizedException;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Security\Auth\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthMiddleware implements MiddlewareInterface
{
	/**
	 * @var array
	 */
	protected array $options;

	/**
	 * @var Auth
	 */
	protected Auth $auth;

	/**
	 * @var Config
	 */
	protected Config $config;

	/**
	 * @var EntityManagerInterface|null
	 */
	protected EntityManagerInterface|null $entityManager;


	public function __construct($options, Config $config, EntityManagerInterface $em = null)
	{
		$this->options = $options;
		$this->auth = new Auth($config, $em);
		$this->config = $config;
		$this->entityManager = $em;
	}

	/**
	 * {@inheritdoc}
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$valid = false;
		if ($this->options["auth_method"] == Auth::JWT) {
			if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
				if (preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
					$jwt = $matches[1];
					if ($jwt) {
						if ($this->auth->validateJWT($jwt)) {
							$valid = true;
						}
					}
				}
			}
		}

		if ($this->options["auth_method"] == Auth::Cookie) {
			if (isset($_COOKIE["auth_token"])) {
				$jwt = $_COOKIE["auth_token"];
				if ($jwt) {
					if ($this->auth->validateJWT($jwt)) {
						$valid = true;
					} else {
                        setcookie('auth_token', null, time() - 3600, secure: true, httponly: true);
					}
				}
			}
		}

		if ($this->options["auth_method"] == Auth::Session) {
			$sessionRep = $this->config->get(array('route', 'modules', 'Session', 'entity', 'session'));
			$sessionRepository = $this->entityManager->getRepository($sessionRep);
			try {
				$sessionRepository->removeExpired();
			} catch (\Exception $e) {
			}
			if (!empty($_SESSION[$this->config->get(array('route', 'modules', 'Session', 'session_key'), 'user')])) {
				$session = $sessionRepository->findOneBy(array($this->config->get(array('route', 'modules', 'Session', 'entity', 'session_key'), 'userid') => $_SESSION[$this->config->get(array('route', 'modules', 'Session', 'session_key'), 'user')], 'sessionid' => session_id()));
				if ($session) {
					$getter = "get" . ucfirst($this->config->get(array('route', 'modules', 'Session', 'entity', 'session_key'), 'userid'));
					$sessionUser = $session->$getter();
					if ($sessionUser) {
						if ($this->password_match($sessionUser, $session)) {
							$valid = true;
						} else {
							$this->entityManager->remove($session);
							$this->entityManager->flush();
						}
					}
				}
			}
			if (!$valid) {
				unset($_SESSION[$this->config->get(array('route', 'modules', 'Session', 'session_key'), 'user')]);
				session_destroy();
			}
		}

		if ($valid) {
			return $handler->handle($request);
		}
		throw new UnauthorizedException();
	}

	/**
	 * @param $sessionUser
	 * @param $session
	 * @return bool
	 */
	protected function password_match($sessionUser, $session): bool
	{
		$sessionRep = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user'));
		$userEntity = $this->entityManager->getRepository($sessionRep)->findOneBy(array('id' => $sessionUser->getId()));
		return hash_equals($userEntity->getPassword(), $session->getHash());
	}
}