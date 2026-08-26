<?php

namespace CodeCTRL\Apollo\Database\Redis;

use CodeCTRL\Apollo\Core\Config\ConfigurableFactoryInterface;
use CodeCTRL\Apollo\Core\Config\ConfigurableFactoryTrait;
use CodeCTRL\Apollo\Core\Factory\InvokableFactoryInterface;
use CodeCTRL\Apollo\Utility\Logger\Logger;
use Psr\Log\LoggerInterface;

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
     * @param LoggerInterface|null $logger
     * @return \Redis|null
     */
    public function createInstance(LoggerInterface $logger = null)
    {
        $host = (string)$this->config->get('host', '127.0.0.1');
        $port = (int)$this->config->get('port', '6379');
        $timeout = (float)$this->config->get('timeout', '2.0');
        $password = $this->config->get('password');
        $database = $this->config->get('database');
        $options = (array)$this->config->get('options', array());

        if (!class_exists('\Redis')) {
            if ($logger instanceof LoggerInterface) {
                $logger->error('Redis class does not exist. Ensure the Redis extension is installed and enabled.');
            }
            return null;
        }

        try {
            $redis = new \Redis();
            $redis->connect($host, $port, $timeout);

            if (!empty($password)) {
                $redis->auth($password);
            }

            if (null !== $database) {
                $redis->select((int)$database);
            }

            if(!empty($options)){
                foreach ($options as $optionKey => $optionVal){
                    $redis->setOption($optionKey, $optionVal);
                }
            }

        } catch (\RedisException $e) {
            if ($logger instanceof LoggerInterface) {
                $logger->error('redis connect', (array)$e);
            }
            throw $e;
        }

        return $redis;
    }
}