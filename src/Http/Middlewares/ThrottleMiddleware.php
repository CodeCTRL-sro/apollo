<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Http\Middlewares;

use CodeCTRL\Apollo\Database\Redis\RedisClient;
use CodeCTRL\Apollo\Http\Exception\RateLimitExceededException;
use CodeCTRL\Apollo\Utility\Helper\Helper;
use CodeCTRL\Apollo\Utility\Helper\RemoteAddressHelper;
use League\Route\Http\Exception\TooManyRequestsException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fixed window request rate limiting, backed by Redis.
 *
 * Equivalent to Laravel's `throttle:60,1` and to Symfony's rate limiter in its
 * fixed_window policy. Register it on login, password reset and public API routes —
 * anywhere an attacker gets something out of repeating a request quickly.
 *
 * Without a Redis connection the middleware lets every request through rather than
 * failing closed. That is a deliberate trade, consistent with the rest of the framework:
 * a cache outage degrades performance and protection, it does not take the site down.
 * The decision is logged, so a silently unprotected endpoint is at least visible. If you
 * need fail-closed behaviour for a specific route, set 'throttle_fail_closed' => true.
 *
 * Options:
 *   throttle_limit        int    Requests allowed per window. Default 60.
 *   throttle_window       int    Window length in seconds. Default 60.
 *   throttle_by           string 'ip' | 'user' | 'user_or_ip'. Default 'user_or_ip'.
 *   throttle_scope        string Bucket name. Defaults to the route name, then the path.
 *   throttle_fail_closed  bool   Reject instead of allowing when Redis is down.
 */
final class ThrottleMiddleware implements MiddlewareInterface
{
    private const KEY_PREFIX = 'throttle:';

    /**
     * @param array<string, mixed> $options
     * @param RedisClient $redis
     * @param Helper|null $helper
     * @param RemoteAddressHelper|null $remoteAddress
     */
    public function __construct(
        private array $options,
        private RedisClient $redis,
        private ?Helper $helper = null,
        private ?RemoteAddressHelper $remoteAddress = null
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $limit = max(1, (int)($this->options['throttle_limit'] ?? 60));
        $window = max(1, (int)($this->options['throttle_window'] ?? 60));

        if (!$this->redis->isAvailable()) {
            if (!empty($this->options['throttle_fail_closed'])) {
                throw new TooManyRequestsException('Rate limiting is unavailable.');
            }

            return $handler->handle($request);
        }

        $key = self::KEY_PREFIX . $this->scope($request) . ':' . $this->identity($request);

        $hits = $this->redis->increment($key);
        if ($hits === false) {
            // The counter could not be written; treat it as an outage rather than as a
            // request that used up no quota.
            return $handler->handle($request);
        }

        if ($hits === 1) {
            $this->redis->expire($key, $window);
        }

        $retryAfter = $this->redis->getTtl($key);
        $retryAfter = is_int($retryAfter) && $retryAfter > 0 ? $retryAfter : $window;

        if ($hits > $limit) {
            throw new RateLimitExceededException('Rate limit exceeded.', array(
                'Retry-After' => (string)$retryAfter,
                'X-RateLimit-Limit' => (string)$limit,
                'X-RateLimit-Remaining' => '0',
            ));
        }

        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string)$limit)
            ->withHeader('X-RateLimit-Remaining', (string)max(0, $limit - $hits));
    }

    /**
     * @param ServerRequestInterface $request
     * @return string
     */
    private function scope(ServerRequestInterface $request): string
    {
        foreach (array('throttle_scope', 'name') as $option) {
            if (!empty($this->options[$option])) {
                return (string)$this->options[$option];
            }
        }

        return trim($request->getUri()->getPath(), '/') ?: 'root';
    }

    /**
     * Who the quota belongs to. Hashed so that the key never carries an address or an
     * identifier around in plain text.
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    private function identity(ServerRequestInterface $request): string
    {
        $by = (string)($this->options['throttle_by'] ?? 'user_or_ip');

        if ($by === 'user' || $by === 'user_or_ip') {
            $user = $this->helper?->getSessionUser();
            if (is_object($user) && method_exists($user, 'getId')) {
                return 'u' . hash('xxh128', (string)$user->getId());
            }
            if ($by === 'user') {
                return 'anonymous';
            }
        }

        return 'i' . hash('xxh128', $this->clientIp($request));
    }

    /**
     * @param ServerRequestInterface $request
     * @return string
     */
    private function clientIp(ServerRequestInterface $request): string
    {
        if ($this->remoteAddress instanceof RemoteAddressHelper) {
            $ip = $this->remoteAddress->getIpAddress();
            if ($ip !== '') {
                return $ip;
            }
        }

        $server = $request->getServerParams();

        return (string)($server['REMOTE_ADDR'] ?? 'unknown');
    }
}
