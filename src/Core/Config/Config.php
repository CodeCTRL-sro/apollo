<?php

namespace CodeCTRL\Apollo\Core\Config;

use CodeCTRL\Apollo\Utility\Utils\ArrayUtils;

class Config
{
    /**
     * @var array
     */
    private array $config;

    /**
     * @var array
     */
    private array $base = array();

    /**
     * Config constructor.
     * @param array $config
     * @param array|string|bool|null $base
     */
    final public function __construct(array $config = array(), array|string|bool $base = null)
    {
        $this->config = $config;
        $this->setBase($base);
    }

    /**
     * @param array|string|bool $dimensionNames
     * @return bool|array|string|bool|null
     */
    public function has(array|string|bool $dimensionNames): bool|array|string|null
    {
        $keys = $this->_keys($dimensionNames);
        return $this->_has($keys);
    }

    /**
     * @param array|string|bool|null $dimensionNames
     * @param array|string|bool|null $default
     * @return array|string|bool|null
     */
    public function get(array|string|bool $dimensionNames = null, array|string|bool $default = null): array|string|bool|null
    {
        $config = $this->_get($this->_keys($dimensionNames), $this->config);
        return is_null($config) ? $default : $config;
    }

    /**
     * @param array|string|bool|null $dimensionNames
     * @param array|string|bool|null $value
     * @return $this
     */
    public function set(array|string|bool $dimensionNames = null, array|string|bool $value = null): static
    {
        $this->_set($this->_keys($dimensionNames), $value);
        return $this;
    }

    /**
     * @param array|string|bool|null $dimensionNames
     * @return self
     */
    public function fromDimension(array|string|bool $dimensionNames = null): self
    {
        $config = $this->_get($this->_keys($dimensionNames), $this->config);
        return new self((array)$config);
    }

    /**
     * @param array|string|bool|null $dimensionNames
     * @return array
     */
    private function _keys(array|string|bool $dimensionNames = null): array
    {
        if (!is_null($dimensionNames) && !is_array($dimensionNames)) {
            $dimensionNames = array($dimensionNames);
        }
        $keys = $this->base;
        if (!empty($dimensionNames)) {
            foreach ($dimensionNames as $dimensionName) {
                $keys[] = $dimensionName;
            }
        }
        return $keys;
    }

    /**
     * @param array $keys
     * @return bool
     */
    private function _has(array $keys): bool
    {
        $config = $this->config;
        foreach ($keys as $key) {
            if (is_array($config) && array_key_exists($key, $config)) {
                $config = $config[$key];
            } else {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array $keys
     * @param array|null $config
     * @return array|string|bool|null
     */
    private function _get(array $keys = array(), array $config = null): array|string|bool|null
    {
        foreach ($keys as $key) {
            if (is_array($config) && array_key_exists($key, $config)) {
                $config = $config[$key];
            } else {
                return null;
            }
        }
        return $config;
    }

    /**
     * @param array $keys
     * @param array|string|bool|null $value
     */
    private function _set(array $keys, array|string|bool $value = null): void
    {
        $cfg = &$this->config;
        foreach ($keys as $key) {
            if (!array_key_exists($key, $cfg)) {
                $cfg[$key] = null;
            }
            $cfg = &$cfg[$key];
        }
        $cfg = $value;
    }

    /**
     * @param array|string|bool|null $base
     * @return $this
     */
    public function setBase(array|string|bool $base = null): static
    {
        if (!is_null($base) && !is_array($base)) {
            $base = array($base);
        }
        $this->base = (array)$base;
        return $this;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * @param array|Config $config
     * @param bool $preserveNumericKeys
     * @return $this
     */
    public function merge(array|Config $config, bool $preserveNumericKeys = false): static
    {
        $merge = $config instanceof self ? $config->toArray() : (array)$config;
        $this->config = ArrayUtils::merge($this->config, $merge, $preserveNumericKeys);
        return $this;
    }
}
