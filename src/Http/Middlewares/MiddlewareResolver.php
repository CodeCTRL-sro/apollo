<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Http\Middlewares;

use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Database\Redis\RedisClient;
use CodeCTRL\Apollo\Utility\Helper\Helper;
use CodeCTRL\Apollo\Utility\Helper\RemoteAddressHelper;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Turns middleware names into middleware instances.
 *
 * Before 3.3.0 the only way onto a route's middleware chain was one of the six hardcoded
 * `if (!empty($requires[...]))` branches in RouteValidator, plus a single `middleware`
 * key with a fixed constructor signature. Adding anything else meant editing the
 * framework. This is the Laravel arrangement instead: short aliases, reusable groups, and
 * `alias:arg,arg` parameters.
 *
 *     'middleware' => array(
 *         'aliases' => array(
 *             'audit' => \App\Http\AuditMiddleware::class,
 *         ),
 *         'groups' => array(
 *             'web' => array('csrf', 'headers'),
 *             'api' => array('headers', 'throttle:60,60'),
 *         ),
 *     ),
 *
 * and on a route: `'middleware' => array('web', 'auth', 'can:users,read')`.
 *
 * Groups may reference other groups; a cycle is reported rather than followed.
 */
final class MiddlewareResolver
{
    /**
     * Aliases the framework ships. An application alias of the same name wins.
     *
     * 'params' names the option keys that positional arguments map onto, so
     * `throttle:60,60` becomes throttle_limit=60, throttle_window=60.
     *
     * @var array<string, array{class: class-string, params?: array<int, string>}>
     */
    private const BUILTIN_ALIASES = array(
        'auth' => array('class' => AuthMiddleware::class, 'params' => array('auth_method')),
        'can' => array('class' => PermissionMiddleware::class, 'params' => array('permission_module', 'permission_right')),
        'can_group' => array('class' => PermissionGroupMiddleware::class, 'params' => array('permission_group')),
        'csrf' => array('class' => CsrfMiddleware::class),
        'headers' => array('class' => SecurityHeadersMiddleware::class),
        'throttle' => array('class' => ThrottleMiddleware::class, 'params' => array('throttle_limit', 'throttle_window')),
        'fields' => array('class' => FieldsMiddleware::class),
        'content_type' => array('class' => ContentTypeMiddleware::class, 'params' => array('required_ContentType')),
        'required_headers' => array('class' => HeadersMiddleware::class),
    );

    public function __construct(
        private ContainerInterface $container,
        private Config $config,
        private ?Helper $helper = null,
        private ?EntityManagerInterface $entityManager = null
    ) {
    }

    /**
     * @param array<int, string>|string $specs
     * @param array<string, mixed> $options Route options merged into every middleware.
     * @return array<int, MiddlewareInterface>
     */
    public function resolve(array|string $specs, array $options = array()): array
    {
        $resolved = array();

        foreach ((array)$specs as $spec) {
            if ($spec instanceof MiddlewareInterface) {
                $resolved[] = $spec;
                continue;
            }
            if (!is_string($spec) || $spec === '') {
                continue;
            }
            foreach ($this->resolveOne($spec, $options, array()) as $middleware) {
                $resolved[] = $middleware;
            }
        }

        return $resolved;
    }

    /**
     * @param string $spec
     * @param array<string, mixed> $options
     * @param array<int, string> $seenGroups
     * @return array<int, MiddlewareInterface>
     */
    private function resolveOne(string $spec, array $options, array $seenGroups): array
    {
        [$name, $arguments] = $this->parse($spec);

        $groups = (array)$this->config->get(array('middleware', 'groups'), array());
        if (isset($groups[$name])) {
            if (in_array($name, $seenGroups, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Middleware group "%s" refers to itself (chain: %s).',
                    $name,
                    implode(' -> ', array_merge($seenGroups, array($name)))
                ));
            }
            $seenGroups[] = $name;

            $resolved = array();
            foreach ((array)$groups[$name] as $member) {
                foreach ($this->resolveOne((string)$member, $options, $seenGroups) as $middleware) {
                    $resolved[] = $middleware;
                }
            }

            return $resolved;
        }

        $definition = $this->definition($name);
        $options = $this->applyArguments($definition, $arguments, $options);

        return array($this->build($definition['class'], $options));
    }

    /**
     * "throttle:60,60" => ['throttle', ['60', '60']]
     *
     * @param string $spec
     * @return array{0: string, 1: array<int, string>}
     */
    private function parse(string $spec): array
    {
        $parts = explode(':', $spec, 2);
        $name = trim($parts[0]);
        $arguments = isset($parts[1]) && $parts[1] !== ''
            ? array_map('trim', explode(',', $parts[1]))
            : array();

        return array($name, $arguments);
    }

    /**
     * @param string $name
     * @return array{class: class-string, params?: array<int, string>}
     */
    private function definition(string $name): array
    {
        $aliases = (array)$this->config->get(array('middleware', 'aliases'), array());

        if (isset($aliases[$name])) {
            $definition = $aliases[$name];
            if (is_string($definition)) {
                $definition = array('class' => $definition);
            }
            if (!is_array($definition) || empty($definition['class'])) {
                throw new InvalidArgumentException(sprintf('Middleware alias "%s" has no class.', $name));
            }

            return $definition;
        }

        if (isset(self::BUILTIN_ALIASES[$name])) {
            return self::BUILTIN_ALIASES[$name];
        }

        // Not an alias: allow a fully qualified class name inline.
        if (class_exists($name)) {
            return array('class' => $name);
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown middleware "%s". Register it under middleware.aliases, or use a class name.',
            $name
        ));
    }

    /**
     * @param array{class: class-string, params?: array<int, string>} $definition
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function applyArguments(array $definition, array $arguments, array $options): array
    {
        if (!empty($definition['options']) && is_array($definition['options'])) {
            $options = array_merge($options, $definition['options']);
        }

        $params = $definition['params'] ?? array();
        foreach ($arguments as $index => $value) {
            if (isset($params[$index])) {
                $options[$params[$index]] = $value;
            }
        }

        // `can:users,read` reads naturally but PermissionMiddleware wants the pair shape
        // it already understands, so translate rather than teach it a second format.
        if (isset($options['permission_module'], $options['permission_right'])) {
            $options['require_permissions'] = array(
                array($options['permission_module'], $options['permission_right']),
            );
            unset($options['permission_module'], $options['permission_right']);
        }
        if (isset($options['permission_group'])) {
            $options['required_permission_groups'] = (array)$options['permission_group'];
            unset($options['permission_group']);
        }

        return $options;
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $options
     * @return MiddlewareInterface
     */
    private function build(string $class, array $options): MiddlewareInterface
    {
        $middleware = match ($class) {
            AuthMiddleware::class => new AuthMiddleware($options, $this->config, $this->entityManager),
            PermissionMiddleware::class => new PermissionMiddleware($options, $this->config, $this->helper, $this->entityManager),
            PermissionGroupMiddleware::class => new PermissionGroupMiddleware($options, $this->config, $this->helper, $this->entityManager),
            CsrfMiddleware::class => new CsrfMiddleware($options),
            SecurityHeadersMiddleware::class => new SecurityHeadersMiddleware($this->securityHeaderOptions($options)),
            ThrottleMiddleware::class => new ThrottleMiddleware($options, $this->redisClient(), $this->helper, $this->remoteAddress()),
            FieldsMiddleware::class => new FieldsMiddleware($options, $this->config, $this->container, $this->entityManager),
            HeadersMiddleware::class => new HeadersMiddleware($options, $this->config, $this->entityManager),
            ContentTypeMiddleware::class => new ContentTypeMiddleware($options, $this->config, $this->entityManager),
            default => $this->buildCustom($class, $options),
        };

        if (!$middleware instanceof MiddlewareInterface) {
            throw new InvalidArgumentException(sprintf(
                '%s must implement %s to be used as middleware.',
                $class,
                MiddlewareInterface::class
            ));
        }

        return $middleware;
    }

    /**
     * Application middleware. Prefer the container so it can be autowired; fall back to
     * the two constructor shapes Apollo has used.
     *
     * @param class-string $class
     * @param array<string, mixed> $options
     * @return object
     */
    private function buildCustom(string $class, array $options): object
    {
        if ($this->container->has($class)) {
            $resolved = $this->container->get($class);
            if ($resolved instanceof MiddlewareInterface) {
                return $resolved;
            }
        }

        try {
            return new $class($options, $this->container, $this->config);
        } catch (\ArgumentCountError|\TypeError) {
            return new $class($options);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function securityHeaderOptions(array $options): array
    {
        if (isset($options['security_headers'])) {
            return $options;
        }

        return array('security_headers' => (array)$this->config->get('security_headers', array()));
    }

    /**
     * @return RedisClient
     */
    private function redisClient(): RedisClient
    {
        if ($this->container->has(RedisClient::class)) {
            $client = $this->container->get(RedisClient::class);
            if ($client instanceof RedisClient) {
                return $client;
            }
        }

        $redis = null;
        if ($this->container->has(\Redis::class)) {
            $resolved = $this->container->get(\Redis::class);
            $redis = $resolved instanceof \Redis ? $resolved : null;
        }

        return new RedisClient($redis, null, (string)$this->config->get(array('redis', 'prefix'), ''));
    }

    /**
     * @return RemoteAddressHelper
     */
    private function remoteAddress(): RemoteAddressHelper
    {
        return RemoteAddressHelper::fromOptions((array)$this->config->get('routing', array()));
    }
}
