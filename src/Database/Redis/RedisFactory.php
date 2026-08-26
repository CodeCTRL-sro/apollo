<?php

namespace CodeCTRL\Apollo\Database\Redis;

use CodeCTRL\Apollo\Core\Config\ConfigurableFactoryInterface;
use CodeCTRL\Apollo\Core\Config\ConfigurableFactoryTrait;
use CodeCTRL\Apollo\Core\Factory\InvokableFactoryInterface;
use CodeCTRL\Apollo\Utility\Logger\Logger;
use Psr\Log\LoggerInterface;

/**
 * Builds the shared \Redis connection from the `redis` configuration.
 *
 * Two equivalent forms are accepted. A DSN keeps the whole connection in a single
 * environment variable:
 *
 *     'redis' => array(
 *         'dsn' => 'redis://:password@127.0.0.1:6379/0?prefix=app_',
 *     )
 *
 * or the individual parameters:
 *
 *     'redis' => array(
 *         'host' => '127.0.0.1', 'port' => 6379, 'password' => '...',
 *         'database' => 0, 'timeout' => 2.0, 'options' => array(\Redis::OPT_PREFIX => 'app_'),
 *     )
 *
 * When a DSN is given it supplies the connection parameters, and the explicit keys stand
 * in for whatever the DSN leaves out.
 *
 * Supported DSN shapes: redis://host, redis://host:port, redis://password@host,
 * redis://username:password@host, a /N path or ?database=N for the database index, plus
 * the ?timeout= / ?prefix= / ?username= / ?password= query parameters. The rediss:// and
 * tls:// schemes connect over TLS.
 *
 * On credentials: the half before the colon in the userinfo is NOT sent to AUTH, matching
 * how Symfony's RedisAdapter reads the same DSN — so this factory and the Doctrine cache
 * authenticate identically against one URL. Redis 6 ACL users must be named explicitly,
 * through the `username` config key or the ?username= query parameter.
 */
class RedisFactory implements InvokableFactoryInterface, ConfigurableFactoryInterface
{
    use ConfigurableFactoryTrait;

    /**
     * @return \Redis|null
     */
    public function __invoke()
    {
        $logger = new Logger('REDIS');

        if (null == $this->config) {
            $logger->error('Factory', (array)" can't work without configuration");
            throw new \Exception(__CLASS__ . " can't work without configuration");
        }

        $redis = $this->createInstance($logger);
        return $redis;
    }

    /**
     * Returns the connected client, or null when Redis is unavailable: the extension is
     * missing, the DSN is unusable, or the server cannot be reached. Callers must treat
     * null as "Redis is down" — a cache or rate-limit backend being unreachable must not
     * take the application down. RedisClient accepts null and degrades every operation
     * into a miss.
     *
     * @param LoggerInterface|null $logger
     * @return \Redis|null
     */
    public function createInstance(LoggerInterface $logger = null)
    {
        if (!class_exists('\Redis')) {
            if ($logger instanceof LoggerInterface) {
                $logger->error('Redis class does not exist. Ensure the Redis extension is installed and enabled.');
            }
            return null;
        }

        try {
            $params = $this->resolveParameters();
        } catch (\InvalidArgumentException $e) {
            if ($logger instanceof LoggerInterface) {
                $logger->error('redis config', (array)$e->getMessage());
            }
            return null;
        }

        try {
            $redis = new \Redis();
            $redis->connect($params['host'], $params['port'], $params['timeout']);

            if (!empty($params['password'])) {
                if (!empty($params['username'])) {
                    $redis->auth(array($params['username'], $params['password']));
                } else {
                    $redis->auth($params['password']);
                }
            }

            if (null !== $params['database']) {
                $redis->select((int)$params['database']);
            }

            if (!empty($params['options'])) {
                foreach ($params['options'] as $optionKey => $optionVal) {
                    $redis->setOption($optionKey, $optionVal);
                }
            }
        } catch (\RedisException $e) {
            if ($logger instanceof LoggerInterface) {
                $logger->error('redis connect', (array)$e->getMessage());
            }
            return null;
        }

        return $redis;
    }

    /**
     * Merges the explicit configuration keys with the DSN, when one is given.
     *
     * @return array
     */
    private function resolveParameters(): array
    {
        $params = array(
            'host' => (string)$this->config->get('host', '127.0.0.1'),
            'port' => (int)$this->config->get('port', '6379'),
            'timeout' => (float)$this->config->get('timeout', '2.0'),
            'username' => $this->config->get('username'),
            'password' => $this->config->get('password'),
            'database' => $this->config->get('database'),
            'options' => (array)$this->config->get('options', array()),
        );

        $dsn = trim((string)$this->config->get('dsn', ''));
        if ($dsn === '') {
            return $params;
        }

        return array_merge($params, $this->parseDsn($dsn, $params['options']));
    }

    /**
     * @param string $dsn
     * @param array $options Options from the explicit configuration; ?prefix= is merged into these.
     * @return array
     * @throws \InvalidArgumentException
     */
    private function parseDsn(string $dsn, array $options): array
    {
        $parts = parse_url($dsn);

        if ($parts === false || empty($parts['host'])) {
            throw new \InvalidArgumentException(sprintf('Unparsable Redis DSN: "%s"', $dsn));
        }

        $scheme = strtolower($parts['scheme'] ?? 'redis');
        if (!in_array($scheme, array('redis', 'rediss', 'tls'), true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported Redis DSN scheme "%s"', $scheme));
        }

        $parsed = array(
            'host' => $scheme === 'redis' ? $parts['host'] : 'tls://' . $parts['host'],
            'port' => isset($parts['port']) ? (int)$parts['port'] : 6379,
        );

        // redis://username:password@host, and the shorthand redis://password@host. Only the
        // password half reaches AUTH; see the class docblock.
        if (isset($parts['pass']) && $parts['pass'] !== '') {
            $parsed['password'] = rawurldecode($parts['pass']);
        } elseif (!isset($parts['pass']) && isset($parts['user']) && $parts['user'] !== '') {
            $parsed['password'] = rawurldecode($parts['user']);
        }

        $path = trim($parts['path'] ?? '', '/');
        if ($path !== '' && ctype_digit($path)) {
            $parsed['database'] = (int)$path;
        }

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);

            foreach (array('database', 'db') as $databaseKey) {
                if (isset($query[$databaseKey]) && $query[$databaseKey] !== '') {
                    $parsed['database'] = (int)$query[$databaseKey];
                }
            }
            if (isset($query['timeout']) && $query['timeout'] !== '') {
                $parsed['timeout'] = (float)$query['timeout'];
            }
            if (isset($query['username']) && $query['username'] !== '') {
                $parsed['username'] = $query['username'];
            }
            if (isset($query['password']) && $query['password'] !== '') {
                $parsed['password'] = $query['password'];
            }
            if (isset($query['prefix']) && $query['prefix'] !== '') {
                $options[\Redis::OPT_PREFIX] = $query['prefix'];
                $parsed['options'] = $options;
            }
        }

        return $parsed;
    }
}
