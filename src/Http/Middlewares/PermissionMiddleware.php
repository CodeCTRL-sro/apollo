<?php

namespace CodeCTRL\Apollo\Http\Middlewares;

use Doctrine\ORM\EntityManagerInterface;
use League\Route\Http\Exception\ForbiddenException;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Security\Auth\Auth;
use CodeCTRL\Apollo\Utility\Helper\Helper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PermissionMiddleware implements MiddlewareInterface
{
    /**
     * @var array
     */
    protected $options;

    /**
     * @var Auth
     */
    protected $auth;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var EntityManagerInterface|null
     */
    protected $entityManager;

    /**
     * Either a Helper, which resolves the user when the route actually runs, or an
     * already resolved user for callers still using the pre-3.3.0 signature.
     *
     * @var Helper|object|false|null
     */
    protected $userSource;

    /**
     * @param array $options
     * @param Config $config
     * @param Helper|object|false|null $userSource Pass a Helper. Passing a resolved user
     *        is deprecated: it forces the lookup at route registration time, so every
     *        permission-guarded route queries the database on every request, including
     *        the requests that never reach it.
     * @param EntityManagerInterface|null $em
     */
    public function __construct($options, Config $config, $userSource, EntityManagerInterface $em = null)
    {
        $this->options = $options;
        $this->auth = new Auth($config, $em);
        $this->config = $config;
        $this->entityManager = $em;
        $this->userSource = $userSource;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $sessionUser = $this->resolveUser();

        foreach ((array)($this->options['require_permissions'] ?? array()) as $require_permission) {
            if (!is_array($require_permission) || count($require_permission) < 2) {
                continue;
            }
            list($module, $right) = $require_permission;
            if (!$sessionUser || !method_exists($sessionUser, 'hasPermission') || !$sessionUser->hasPermission($module, $right)) {
                throw new ForbiddenException();
            }
        }

        return $handler->handle($request);
    }

    /**
     * @return object|false
     */
    protected function resolveUser()
    {
        if ($this->userSource instanceof Helper) {
            return $this->userSource->getSessionUser();
        }

        return is_object($this->userSource) ? $this->userSource : false;
    }
}
