<?php

namespace CodeCTRL\Apollo\Database\Redis;

use Psr\Log\LoggerInterface;

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
     * @param LoggerInterface|null $logger
     */
    public function __construct(\Redis $redis = null, LoggerInterface $logger = null)
    {
        $this->redis = $redis;
        $this->logger = $logger;
    }

    /**
     * @param string $key
     * @return string
     */
    private function getKey(string $key): string
    {
        return $key;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl
     * @return bool
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
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
        try {
            $keys = $this->redis->keys($this->getKey($pattern));
            if (!empty($keys)) {
                return (bool) $this->redis->del($keys);
            }
            return true;
        } catch (\RedisException $e) {
            $this->handleError($e);
            return false;
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
        try {
            return $this->redis->ttl($this->getKey($key));
        } catch (\RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }
}
