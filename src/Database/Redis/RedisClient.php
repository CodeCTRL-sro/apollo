<?php

namespace CodeCTRL\Apollo\Database\Redis;

use Psr\Log\LoggerInterface;

/**
 * Thin, failure-tolerant wrapper over the \Redis connection.
 *
 * The client is deliberately usable without a connection: RedisFactory returns null when
 * Redis is unreachable, and some containers hand their modules a RedisClient that wraps
 * null. Every operation therefore degrades instead of throwing — reads report a miss,
 * writes report failure, and remember() still runs its callback — so a Redis outage
 * costs performance, never availability. Use isAvailable() when a caller needs to know.
 */
class RedisClient
{
    /**
     * @var \Redis|null
     */
    private $redis;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    private $defaultTtl = 3600;

    /**
     * Prepended to every key. Empty by default, so keys written by earlier releases are
     * found unchanged; set it when several applications share one Redis database, which
     * also keeps clearByPattern() from sweeping a neighbour's keys.
     *
     * @var string
     */
    private string $prefix = '';

    /**
     * @param \Redis|null $redis
     * @param LoggerInterface|null $logger
     * @param string $prefix
     */
    public function __construct(\Redis $redis = null, LoggerInterface $logger = null, string $prefix = '')
    {
        $this->redis = $redis;
        $this->logger = $logger;
        $this->setPrefix($prefix);
    }

    /**
     * @param string $prefix
     * @return $this
     */
    public function setPrefix(string $prefix): static
    {
        $this->prefix = $prefix === '' ? '' : rtrim($prefix, ':') . ':';

        return $this;
    }

    /**
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Whether a Redis connection is present. False means every operation on this client
     * is a no-op: reads miss, writes fail, remember() computes without caching.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->redis instanceof \Redis;
    }

    /**
     * @param string $key
     * @return string
     */
    private function getKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl
     * @return bool
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $ttl = $ttl ?? $this->defaultTtl;
        $value = is_array($value) ? json_encode($value) : $value;

        try {
            return $this->redis->setex($this->getKey($key), $ttl, $value);
        } catch (\RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * @param string $key
     * @param bool $asArray
     * @return mixed
     */
    public function get(string $key, bool $asArray = true)
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $value = $this->redis->get($this->getKey($key));

            if ($value === false) {
                return null;
            }

            if ($asArray && $this->isJson($value)) {
                return json_decode($value, true);
            }

            return $value;
        } catch (\RedisException $e) {
            $this->handleError($e);
            return null;
        }
    }

    /**
     * Returns the cached value, or computes it with $callback and caches it. Without a
     * connection the callback still runs and its result is returned uncached.
     *
     * @param string $key
     * @param callable $callback
     * @param int|null $ttl
     * @return mixed
     */
    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return (bool) $this->redis->del($this->getKey($key));
        } catch (\RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * @param string $key
     * @return bool
     */
    public function exists(string $key): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return (bool) $this->redis->exists($this->getKey($key));
        } catch (\RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * @param string $key
     * @param int $value
     * @return int|false
     */
    public function increment(string $key, int $value = 1)
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return $this->redis->incrBy($this->getKey($key), $value);
        } catch (\RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * @param array $values
     * @param int|null $ttl
     * @return bool
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $pipeline = $this->redis->pipeline();
            foreach ($values as $key => $value) {
                $value = is_array($value) ? json_encode($value) : $value;
                $pipeline->setex($this->getKey($key), $ttl ?? $this->defaultTtl, $value);
            }
            $pipeline->exec();
            return true;
        } catch (\RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * @param array $keys
     * @param bool $asArray
     * @return array
     */
    public function getMultiple(array $keys, bool $asArray = true): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $prefixedKeys = array_map([$this, 'getKey'], $keys);
            $values = $this->redis->mGet($prefixedKeys);

            $result = [];
            foreach ($keys as $i => $originalKey) {
                $value = $values[$i];
                if ($value !== false) {
                    $result[$originalKey] = $asArray && $this->isJson($value)
                        ? json_decode($value, true)
                        : $value;
                }
            }

            return $result;
        } catch (\RedisException $e) {
            $this->handleError($e);
            return [];
        }
    }

    /**
     * @param string $pattern
     * @return bool
     */
    public function clearByPattern(string $pattern): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        // KEYS walks the entire keyspace in one blocking pass — on a production
        // database that is a stall for every other client on the server. SCAN does the
        // same work in bounded slices, and UNLINK frees the memory on a background
        // thread instead of inline.
        $previousOption = null;
        try {
            $previousOption = $this->redis->getOption(\Redis::OPT_SCAN);
            $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

            $iterator = null;
            $guard = 0;
            while (($keys = $this->redis->scan($iterator, $this->getKey($pattern), 500)) !== false) {
                if (!empty($keys)) {
                    $this->removeKeys($keys);
                }
                // SCAN_RETRY makes scan() return false once the cursor is exhausted;
                // the counter is only a backstop against a pathological server.
                if (++$guard > 100000) {
                    break;
                }
            }

            return true;
        } catch (\RedisException $e) {
            $this->handleError($e);
            return false;
        } finally {
            if ($previousOption !== null) {
                try {
                    $this->redis->setOption(\Redis::OPT_SCAN, $previousOption);
                } catch (\RedisException $e) {
                    $this->handleError($e);
                }
            }
        }
    }

    /**
     * Set or refresh a key's time to live.
     *
     * @param string $key
     * @param int $ttl Seconds.
     * @return bool
     */
    public function expire(string $key, int $ttl): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return (bool) $this->redis->expire($this->getKey($key), $ttl);
        } catch (\RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * UNLINK where the server supports it (Redis 4+), DEL otherwise.
     *
     * @param array<int, string> $keys Already prefixed.
     */
    private function removeKeys(array $keys): void
    {
        try {
            if (method_exists($this->redis, 'unlink')) {
                $this->redis->unlink($keys);
                return;
            }
        } catch (\RedisException $e) {
            // Fall through to DEL: an older server reports UNLINK as an unknown command.
            $this->handleError($e);
        }

        try {
            $this->redis->del($keys);
        } catch (\RedisException $e) {
            $this->handleError($e);
        }
    }

    /**
     * @param string $string
     * @return bool
     */
    private function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @param \RedisException $e
     */
    private function handleError(\RedisException $e): void
    {
        if($this->logger instanceof LoggerInterface) {
            $this->logger->error('redis', (array)$e->getMessage());
        }
    }

    /**
     * @param string $key
     * @return int|false
     */
    public function getTtl(string $key)
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return $this->redis->ttl($this->getKey($key));
        } catch (\RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }
}
