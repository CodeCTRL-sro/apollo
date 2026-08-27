<?php

namespace CodeCTRL\Apollo\Http\Route;

use Doctrine\ORM\EntityManagerInterface;
use League\Container\Container;
use League\Route\Route;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Http\Middlewares\AuthMiddleware;
use CodeCTRL\Apollo\Http\Middlewares\ContentTypeMiddleware;
use CodeCTRL\Apollo\Http\Middlewares\FieldsMiddleware;
use CodeCTRL\Apollo\Http\Middlewares\HeadersMiddleware;
use CodeCTRL\Apollo\Http\Middlewares\MiddlewareResolver;
use CodeCTRL\Apollo\Http\Middlewares\PermissionGroupMiddleware;
use CodeCTRL\Apollo\Http\Middlewares\PermissionMiddleware;
use CodeCTRL\Apollo\Security\Auth\Auth;
use CodeCTRL\Apollo\Utility\Helper\Helper;
use Psr\Http\Server\MiddlewareInterface;
use Twig\Environment;

class RouteValidator implements RouteValidatorInterface
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var \Twig\Environment
     */
    protected $twig;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var Auth
     */
    protected $auth;

    /**
     * @var MiddlewareResolver|null
     */
    protected $middlewareResolver;

    /**
     * RouteValidator constructor.
     * @param Config $config
     * @param \Twig\Environment $twig
     * @param Helper $helper
     * @param Auth $auth
     * @param EntityManagerInterface|null $entityManager
     */
    public function __construct(Config $config, Environment $twig, Helper $helper, Auth $auth, EntityManagerInterface $entityManager = null)
    {
        $this->config = $config;
        $this->twig = $twig;
        $this->entityManager = $entityManager;
        $this->helper = $helper;
        $this->auth = $auth;
    }

	/**
	 * @return Helper
	 */
	public function getHelper(): Helper
	{
		return $this->helper;
	}

	/**
	 * @return EntityManagerInterface
	 */
	public function getEntityManager(): EntityManagerInterface
	{
		return $this->entityManager;
	}

    /**
     * @param Route $map
     * @param array $requires
     * @param array $options
     * @param Container $container
     * @return Route
     */
    public function validate(Route $map, array $requires, array $options, Container $container)
    {
        if (!empty($requires['require_permissions'])) {
            if (!is_array($requires['require_permissions'][0])) {
                $requires['require_permissions'] = array($requires['require_permissions']);
            }
            $options['require_permissions'] = $requires['require_permissions'];
            // The Helper is handed over rather than the resolved user: this runs while
            // routes are being registered, so resolving here queried the database once
            // per guarded route on every request, whatever URL was actually called.
            $map->middleware(new PermissionMiddleware($options, $this->config, $this->helper, $this->entityManager));
        }
        if (!empty($requires['required_permission_groups'])) {
            $options['required_permission_groups'] = $requires['required_permission_groups'];
            $map->middleware(new PermissionGroupMiddleware($options, $this->config, $this->helper, $this->entityManager));
        }
        if ($requires['require_auth']) {
            $options['require_auth'] = $requires['require_auth'];
            $options['auth_method'] = $requires['auth_method'];
            $map->middleware(new AuthMiddleware($options, $this->config, $this->entityManager));
        }
        if (!empty($requires['middleware'])) {
            foreach ($this->resolveMiddleware($requires['middleware'], $options, $container) as $middleware) {
                $map->middleware($middleware);
            }
        }
        if (!empty($requires['required_fields'])) {
            $options['required_fields'] = (array)$requires['required_fields'];
            $map->middleware(new FieldsMiddleware($options, $this->config, $container, $this->entityManager));
        }
        if (!empty($requires['required_headers'])) {
            $options['required_headers'] = (array)$requires['required_headers'];
            $map->middleware(new HeadersMiddleware($options, $this->config, $this->entityManager));
        }
        if ($requires['required_ContentType'] && in_array($requires['required_ContentType'], $options['valid_ContentTypes']) && in_array($options['method'], array('POST', 'PUT', 'PATCH', 'DELETE'))) {
            $options['required_ContentType'] = $requires['required_ContentType'];
            $map->middleware(new ContentTypeMiddleware($options, $this->config, $this->entityManager));
        }
        return $map;
    }

    /**
     * Resolve a route's `middleware` entry.
     *
     * Two shapes are accepted. A list of alias / group / class names goes through
     * MiddlewareResolver, which is what makes `array('web', 'throttle:60,60')` work. A
     * single class name keeps the pre-3.3.0 behaviour, constructed with
     * ($options, $container, $validator) — applications relying on that signature are
     * unaffected.
     *
     * @param array<int, string>|string $spec
     * @param array<string, mixed> $options
     * @param Container $container
     * @return array<int, MiddlewareInterface>
     */
    protected function resolveMiddleware($spec, array $options, Container $container): array
    {
        if (is_string($spec) && class_exists($spec)) {
            return array(new $spec($options, $container, $this));
        }

        return $this->middlewareResolver($container)->resolve($spec, $options);
    }

    /**
     * @param Container $container
     * @return MiddlewareResolver
     */
    protected function middlewareResolver(Container $container): MiddlewareResolver
    {
        if (!$this->middlewareResolver instanceof MiddlewareResolver) {
            $this->middlewareResolver = new MiddlewareResolver(
                $container,
                $this->config,
                $this->helper,
                $this->entityManager
            );
        }

        return $this->middlewareResolver;
    }
}
