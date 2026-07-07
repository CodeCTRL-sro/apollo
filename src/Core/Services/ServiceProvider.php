<?php

namespace CodeCTRL\Apollo\Core\Services;

use GuzzleHttp\Psr7\ServerRequest;
use League\Container\Container;
use League\Container\ContainerAwareInterface;
use League\Container\ReflectionContainer;
use League\Container\ServiceProvider\AbstractServiceProvider;
use League\Container\ServiceProvider\BootableServiceProviderInterface;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Database\Doctrine\EntityManagerProvider;
use CodeCTRL\Apollo\UI\Html\Html;
use CodeCTRL\Apollo\UI\Twig\TwigFactory;
use CodeCTRL\Apollo\Utility\Logger\Logger;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Twig\Environment;

class ServiceProvider extends AbstractServiceProvider implements BootableServiceProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var Config
     */
    protected $config;
    /**
     * @var ServerRequestInterface
     */
    protected $request;


    public function __construct(Config $config, ServerRequestInterface $request = null)
    {
        $this->config = $config;
        if (!$request) {
            $request = ServerRequest::fromGlobals();
        }
        if ($this->config->has(array('routing','basepath'))) {
            $request = Html::removePathPrefix($request, $this->config->get(array('routing', 'basepath')));
        }
        $this->request = $request;
    }

    public function boot() :void
    {
        /** @var Container $container */
        $container = $this->getContainer();

        $container
            ->inflector(ContainerAwareInterface::class)
            ->invokeMethod('setContainer', array('container'=>$container));

        $serviceManager = new ServiceManager($this->getContainer());
        $serviceManager->configure($this->config->fromDimension('services'));

        $container->addShared(ServiceManager::class, $serviceManager);

        $container->delegate($serviceManager);

        $container->delegate(new ReflectionContainer());

        EntityManagerProvider::setContainer($container);

        // Guarantee a Twig environment is always resolvable. Several core services
        // (strategies, the route validator, module containers) type-hint
        // Twig\Environment in their constructor. Without an explicit binding the
        // container would fall back to reflection and hard-fail (Twig\Environment
        // needs a LoaderInterface it cannot autowire). Registering a lazy default
        // lets the framework run headless (no templates configured). A project that
        // registers its own Twig service still takes precedence.
        if (!$serviceManager->has(Environment::class)) {
            $container->addShared(Environment::class, function () use ($container) {
                $factory = new TwigFactory();
                $factory->setContainer($container);
                $config = $container->get(Config::class);
                if ($config->has('twig')) {
                    $factory->configure($config->fromDimension('twig'));
                }
                return $factory();
            });
        }
    }

    public function register() :void
    {
        $this->getContainer()->addShared(LoggerInterface::class, ($this->logger instanceof LoggerInterface ? $this->logger : new Logger('Apollo')));
        $this->getContainer()->addShared(ServerRequestInterface::class, $this->request);
        $this->getContainer()->addShared(Config::class, $this->config);
    }


    public function provides(string $id): bool
    {
        $services = [
            Config::class,
            LoggerInterface::class,
            ServerRequestInterface::class,
            ServiceManager::class,
        ];

        return in_array($id, $services);
    }
}
