<?php

namespace CodeCTRL\Apollo\Database\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Exception;
use Gedmo\Mapping\Driver\AttributeReader;
use League\Container\ContainerAwareInterface;
use League\Container\ContainerAwareTrait;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Core\Config\ConfigurableFactoryInterface;
use CodeCTRL\Apollo\Core\Config\ConfigurableFactoryTrait;
use CodeCTRL\Apollo\Core\Factory\Factory;
use CodeCTRL\Apollo\Core\Factory\InvokableFactoryInterface;
use CodeCTRL\Apollo\Database\Redis\RedisFactory;
use CodeCTRL\Apollo\Utility\Language\Language;
use CodeCTRL\Apollo\Utility\Logger\Logger;
use PDO;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

class DoctrineFactory implements InvokableFactoryInterface, ConfigurableFactoryInterface, ContainerAwareInterface
{
    use ConfigurableFactoryTrait;
    use ContainerAwareTrait;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @return \CodeCTRL\Apollo\Database\Doctrine\EntityManager
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ORMException
     * @throws \Doctrine\DBAL\Exception
     */
    public function __invoke(): ?EntityManager
    {
        $this->logger = new Logger('DOCTRINE');

        if (!($this->config instanceof Config)) {
            // No Doctrine configuration at all → run without a database.
            return null;
        }

        if (!$this->isDatabaseConfigured()) {
            // No database credentials configured (e.g. empty .env) → run without a
            // database instead of trying to connect and hard-failing. Consumers all
            // accept a nullable EntityManager. If credentials ARE present but the
            // connection later fails, that error is still surfaced below.
            return null;
        }

        $this->preparePDO();

        $isDevMode = $this->config->get('devMode', false);
        $routeConfig = Factory::fromNames(['route'], true);
        $defaultLang = Language::parseLang($routeConfig);
        $paths = $this->config->get('paths', []);

        // The cache pool is built up front and passed to ORMSetup explicitly, so the
        // setup tool never falls back to its own auto-detection, which would blindly
        // connect to APCu / Memcached / Redis on 127.0.0.1 whenever devMode is false.
        $cache = $this->createCache($isDevMode);

        $config = ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode, null, $cache);

        $this->addFunctions($config);
        $this->setProxy($config);

        $dbParams = $this->config->get('dbParams');

        try {
            $connection = DriverManager::getConnection($dbParams, $config);
        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logger->error('Doctrine', [$e->getMessage()]);
            throw $e;
        }

        $mappingDriver = new MappingDriverChain();

        $gedmoPaths = [
            dirname((new \ReflectionClass(\Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation::class))->getFileName()),
            dirname((new \ReflectionClass(\Gedmo\Loggable\Entity\MappedSuperclass\AbstractLogEntry::class))->getFileName()),
            dirname((new \ReflectionClass(\Gedmo\Tree\Entity\MappedSuperclass\AbstractClosure::class))->getFileName()),
        ];
        $gedmoDriver = new AttributeDriver($gedmoPaths);
        $mappingDriver->addDriver($gedmoDriver, 'Gedmo');

        $config->setMetadataDriverImpl($mappingDriver);

        $eventManager = new \Doctrine\Common\EventManager();

        $this->configureGedmoListeners($eventManager, $cache, $defaultLang);

        $this->addNamespaces($config, $mappingDriver);

        $config->setMetadataCache($cache);
        $config->setQueryCache($cache);
        $config->setResultCache($cache);

        $entityManager = new EntityManager($connection, $config, $eventManager);

        $this->addTypes();
        $this->addTypeMappings($entityManager);
        $this->addEventListeners($eventManager);
        $this->addEventSubscribers($eventManager);

        return $entityManager;
    }

    /**
     * Decide whether a database is actually configured. When it isn't (e.g. an app
     * that runs without a database, or an empty .env), the factory returns null
     * instead of attempting a connection. A connection is considered configured
     * when explicit dbParams are provided, or when the shared `db` config carries
     * a host / dbname / user.
     *
     * @return bool
     */
    private function isDatabaseConfigured(): bool
    {
        $dbParams = $this->config->get('dbParams');
        if (is_array($dbParams) && !empty(array_filter($dbParams))) {
            return true;
        }

        try {
            $dbConfig = Factory::fromNames(['db'], true);
        } catch (\Throwable $e) {
            return false;
        }

        $host = $dbConfig->get(['db', 'dsn', 'host']);
        $dbname = $dbConfig->get(['db', 'dsn', 'dbname']);
        $user = $dbConfig->get(['db', 'db_user']);

        return !empty($host) || !empty($dbname) || !empty($user);
    }

    /**
     * Build the PSR-6 cache pool used for the metadata / query / result caches and
     * shared with the Gedmo listeners.
     *
     * In dev mode the pool is always an in-memory ArrayAdapter so mapping changes take
     * effect immediately. Otherwise the adapter comes from the `cache` dimension of the
     * Doctrine configuration:
     *
     *     'cache' => [
     *         'adapter'   => 'redis',          // array (default) | redis | apcu | filesystem | php_files
     *         'namespace' => 'apollo_doctrine',
     *         'lifetime'  => 0,
     *         'directory' => '/path/to/cache', // filesystem / php_files only
     *         'redis'     => [                 // optional, see RedisFactory
     *             'dsn' => 'redis://127.0.0.1:6379',
     *             // or: 'host', 'port', 'timeout', 'password', 'database', 'options'
     *         ],
     *     ],
     *
     * The default stays ArrayAdapter so existing applications keep their behaviour, and
     * a failing adapter degrades to an ArrayAdapter instead of taking the application
     * down: a cache outage must not become a fatal error.
     *
     * @param bool $isDevMode
     * @return CacheItemPoolInterface
     */
    private function createCache(bool $isDevMode): CacheItemPoolInterface
    {
        if ($isDevMode) {
            return new ArrayAdapter();
        }

        $cacheConfig = $this->config->fromDimension('cache');
        $adapter = mb_strtolower(trim((string)$cacheConfig->get('adapter', 'array')));
        $namespace = (string)$cacheConfig->get('namespace', 'apollo_doctrine');
        $lifetime = (int)$cacheConfig->get('lifetime', '0');

        try {
            return match ($adapter) {
                '', 'array', 'memory' => new ArrayAdapter($lifetime),
                'redis' => new RedisAdapter($this->createRedisClient($cacheConfig), $namespace, $lifetime),
                'apcu' => new ApcuAdapter($namespace, $lifetime),
                'filesystem', 'file' => new FilesystemAdapter($namespace, $lifetime, $this->getCacheDirectory($cacheConfig)),
                'php_files', 'phpfiles' => new PhpFilesAdapter($namespace, $lifetime, $this->getCacheDirectory($cacheConfig)),
                default => throw new \InvalidArgumentException(sprintf('Unknown Doctrine cache adapter "%s"', $adapter)),
            };
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine', [sprintf(
                'Cache adapter "%s" is unavailable, falling back to ArrayAdapter: %s',
                $adapter,
                $e->getMessage()
            )]);

            return new ArrayAdapter();
        }
    }

    /**
     * Resolve the Redis client backing the Doctrine cache. An explicit DSN wins, then a
     * client already registered in the container is reused (so the cache shares the
     * application connection), then explicit `cache.redis` parameters, and finally the
     * application wide `redis` configuration file.
     *
     * @param Config $cacheConfig
     * @return \Redis|\RedisArray|\RedisCluster|object The client accepted by RedisAdapter
     */
    private function createRedisClient(Config $cacheConfig)
    {
        $redisConfig = (array)$cacheConfig->get('redis', []);

        if (!empty($redisConfig['dsn'])) {
            return RedisAdapter::createConnection($redisConfig['dsn']);
        }

        if (empty($redisConfig)
            && $this->container instanceof ContainerInterface
            && $this->container->has(\Redis::class)
        ) {
            return $this->container->get(\Redis::class);
        }

        if (empty($redisConfig)) {
            try {
                $redisConfig = (array)Factory::fromNames(['redis'], true)->get('redis', []);
            } catch (\Throwable $e) {
                $redisConfig = [];
            }
        }

        if (empty($redisConfig)) {
            throw new \RuntimeException(
                'No Redis connection configured: set the doctrine `cache.redis` parameters, register a \Redis service or add a redis config.'
            );
        }

        $factory = new RedisFactory();
        $factory->configure(new Config($redisConfig));
        $redis = $factory->createInstance($this->logger);

        if (!$redis instanceof \Redis) {
            // RedisFactory returns null when the extension is missing, the DSN is unusable
            // or the server is unreachable; it logs the specific cause. Surfacing it as an
            // exception lets createCache() fall back to an ArrayAdapter.
            throw new \RuntimeException('No usable Redis connection for the Doctrine cache.');
        }

        return $redis;
    }

    /**
     * @param Config $cacheConfig
     * @return string|null
     */
    private function getCacheDirectory(Config $cacheConfig): ?string
    {
        $directory = $cacheConfig->get('directory');

        return empty($directory)
            ? null
            : rtrim((string)$directory, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * @param EventManager $eventManager
     * @param $cache
     * @param $defaultLang
     * @return void
     */
    private function configureGedmoListeners(\Doctrine\Common\EventManager $eventManager, $cache, $defaultLang): void
    {
        $gedmoListeners = [
            'sluggable' => \Gedmo\Sluggable\SluggableListener::class,
            'tree' => \Gedmo\Tree\TreeListener::class,
            'timestampable' => \Gedmo\Timestampable\TimestampableListener::class,
            'blameable' => \Gedmo\Blameable\BlameableListener::class,
            'translatable' => \Gedmo\Translatable\TranslatableListener::class,
        ];

        $enabledListeners = $this->config->get('gedmoListeners', array_keys($gedmoListeners));
        $disabledListeners = $this->config->get('disableGedmoListeners', []);

        foreach ($gedmoListeners as $type => $listenerClass) {
            if (!in_array($type, $enabledListeners) || in_array($type, $disabledListeners)) {
                continue;
            }

            if (class_exists($listenerClass)) {
                $listener = new $listenerClass();
                $listener->setAnnotationReader(new AttributeReader());
                $listener->setCacheItemPool($cache);

                if ($type === 'translatable') {
                    $listener->setDefaultLocale($defaultLang);
                    $listener->setTranslatableLocale($defaultLang);
                    $listener->setTranslationFallback(true);
                    $listener->setPersistDefaultLocaleTranslation(true);
                }

                $eventManager->addEventSubscriber($listener);
            }
        }
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function preparePDO(): void
    {
        if (!$this->config->has('dbParams')) {
            $pdo = null;
            if ($this->container->has(PDO::class)) {
                $pdo = $this->container->get(PDO::class);
            } elseif ($this->config->has('pdo')) {
                $factory = $this->container->get(PdoFactory::class);
                $factory->configure($this->config->fromDimension('pdo'));
                try {
                    $pdo = $factory();
                } catch (Exception $e) {
                    $this->logger->error('Doctrine', array($e->getMessage()));
                    throw $e;
                }
            }
            if ($pdo instanceof PDO) {
                $pdoConfig = Factory::fromNames(array('db'), true);
                $this->config->set(
                    array('dbParams'),
                    array(
                        'pdo' => $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION),
                        'driver' => 'pdo_' . $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),
                        'host' => $pdoConfig->get(array('db','dsn','host')),
                        'port' => $pdoConfig->get(array('db','dsn','port')),
                        'user' => $pdoConfig->get(array('db','db_user')),
                        'dbname' => $pdoConfig->get(array('db','dsn','dbname')),
                        'password' => $pdoConfig->get(array('db','db_pass')),
						'charset' => $pdoConfig->get(array('db','dsn','charset')),
                    )
                );
            }
        }
    }

    /**
     * @param Configuration $config
     * @param MappingDriverChain $mappingDriver
     * @return void
     */
    private function addNamespaces(Configuration $config, MappingDriverChain $mappingDriver): void
    {
        $namespaces = $this->config->get('namespaces', []);
        $paths = $this->config->get('paths', []);

        foreach ($namespaces as $key => $namespace) {
            $config->setEntityNamespaces([$key => $namespace]);

            $driver = new AttributeDriver([$paths[$key] ?? '']);
            $mappingDriver->addDriver($driver, $namespace);
        }
    }

    /**
     * @param Configuration $config
     * @throws ORMException
     */
    private function addFunctions(Configuration $config): void
    {
        if (!empty($this->config->get('functions', []))) {
            foreach ($this->config->get('functions') as $type => $functions) {
                foreach ($functions as $name => $className) {
                    try {
                        match (mb_strtolower($type)) {
                            'string' => $config->addCustomStringFunction($name, $className),
                            'numeric' => $config->addCustomNumericFunction($name, $className),
                            'datetime' => $config->addCustomDatetimeFunction($name, $className),
                            default => null,
                        };
                    } catch (ORMException $e) {
                        $this->logger->error('Doctrine', [$e->getMessage()]);
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * @param Configuration $config
     */
    private function setProxy(Configuration $config): void
    {
        $proxyCfg = $this->config->get('proxy', []);
        if (!empty($proxyCfg)) {
            foreach ($proxyCfg as $key => $val) {
                match ($key) {
                    'mode' => $config->setAutoGenerateProxyClasses($val),
                    'dir' => $config->setProxyDir(rtrim($val, '/\\') . DIRECTORY_SEPARATOR),
                    'namespace' => $config->setProxyNamespace($val),
                    default => null,
                };
            }
        }
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private function addTypes(): void
    {
        $types = $this->config->get('types', []);
        if (!empty($types)) {
            foreach ($types as $name => $className) {
                try {
                    if (Type::hasType($name)) {
                        Type::overrideType($name, $className);
                    } else {
                        Type::addType($name, $className);
                    }
                } catch (\Doctrine\DBAL\Exception $e) {
                    $this->logger->error('Doctrine:types', ['name' => $name, 'class' => $className, 'e' => $e->getMessage()]);
                    throw $e;
                }
            }
        }
    }

    /**
     * @param \CodeCTRL\Apollo\Database\Doctrine\EntityManager $entityManager
     * @throws \Doctrine\DBAL\Exception
     */
    private function addTypeMappings(EntityManager $entityManager): void
    {
        $typeMappings = $this->config->get('typeMappings', []);
        if (!empty($typeMappings)) {
            foreach ($typeMappings as $dbType => $doctrineType) {
                try {
                    $entityManager->getConnection()->getDatabasePlatform()->registerDoctrineTypeMapping($dbType, $doctrineType);
                } catch (\Doctrine\DBAL\Exception $e) {
                    $this->logger->error('Doctrine:typeMappings', ['dbType' => $dbType, 'doctrineType' => $doctrineType, 'e' => $e->getMessage()]);
                    throw $e;
                }
            }
        }
    }

    /**
     * @param EventManager $eventManager
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function addEventListeners(\Doctrine\Common\EventManager $eventManager): void
    {
        $eventListeners = $this->config->get('eventListeners', []);
        if (!empty($eventListeners)) {
            foreach ($eventListeners as $eventListener) {
                if (is_array($eventListener) && !array_diff(['event', 'class'], array_keys($eventListener))) {
                    if (is_string($eventListener['event']) && is_string($eventListener['class'])) {
                        $eventListenerObject = $this->getEventListenerObject($eventListener['class']);
                        $eventManager->addEventListener($eventListener['event'], $eventListenerObject);
                    }
                }
            }
        }
    }

    /**
     * @param string $class
     * @return mixed
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function getEventListenerObject(string $class): mixed
    {
        return $this->resolveOrInstantiate($class, 'Doctrine:eventListeners');
    }

    /**
     * Resolve a class from the container, falling back to constructing it directly.
     *
     * getEventListenerObject() and getEventSubscriberObject() were character for
     * character identical apart from the log channel, so both now delegate here.
     *
     * @param string $class
     * @param string $channel
     * @return object
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function resolveOrInstantiate(string $class, string $channel): object
    {
        if ($this->container->has($class)) {
            try {
                return $this->container->get($class);
            } catch (Exception $e) {
                $this->logger->error($channel, ['class' => $class, 'e' => $e->getMessage()]);
                throw $e;
            }
        }

        if (class_exists($class)) {
            try {
                return new $class();
            } catch (Exception $e) {
                $this->logger->error($channel, ['class' => $class, 'e' => $e->getMessage()]);
                throw $e;
            }
        }

        $this->logger->error($channel, ['class' => $class, 'e' => 'not exists']);
        throw new Exception("{$class} not exists");
    }

    /**
     * @param EventManager $eventManager
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function addEventSubscribers(\Doctrine\Common\EventManager $eventManager): void
    {
        $eventSubscribers = $this->config->get('eventSubscribers', []);
        if (!empty($eventSubscribers)) {
            foreach ($eventSubscribers as $eventSubscriber) {
                if (is_string($eventSubscriber)) {
                    $eventSubscriberObject = $this->getEventSubscriberObject($eventSubscriber);

                    if ($eventSubscriberObject instanceof \Doctrine\Common\EventSubscriber) {
                        $eventManager->addEventSubscriber($eventSubscriberObject);
                    } else {
                        $this->logger->error('Doctrine:eventSubscribers', ['class' => $eventSubscriber, 'e' => 'not instance of Doctrine\\Common\\EventSubscriber']);
                        throw new Exception("{$eventSubscriber} not instance of Doctrine\\Common\\EventSubscriber");
                    }
                }
            }
        }
    }

    /**
     * @param string $class
     * @return mixed
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function getEventSubscriberObject(string $class): mixed
    {
        return $this->resolveOrInstantiate($class, 'Doctrine:eventSubscribers');
    }
}
