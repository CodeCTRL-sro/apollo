<?php

namespace CodeCTRL\Apollo\Core;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Laminas\View\Renderer\PhpRenderer;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Utility\Debug\ErrorRenderer;
use CodeCTRL\Apollo\Http\Route\Router;
use CodeCTRL\Apollo\UI\Form\ConfigProvider;
use CodeCTRL\Apollo\UI\Html\Html;
use CodeCTRL\Apollo\UI\Twig\Interfaces\TwigAwareInterface;
use CodeCTRL\Apollo\UI\Twig\Twig;
use CodeCTRL\Apollo\Utility\Logger\Interfaces\LoggerHelperInterface;
use CodeCTRL\Apollo\Utility\Logger\Traits\LoggerHelperTrait;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\TwigFunction;

class ApolloKernel implements LoggerHelperInterface
{
    use LoggerHelperTrait;

    /**
     * The error types that leave PHP no way to continue, so the shutdown function is the
     * last chance to produce a response.
     */
    private const FATAL_ERROR_TYPES = array(
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
        E_RECOVERABLE_ERROR,
    );

    private ContainerInterface $container;

    private ?\Twig\Environment $twig = null;

    private bool $debug = false;

    public function __construct(ContainerInterface $container)
    {
        if (!ob_get_level()) {
            ob_start();
        }
        $this->container = $container;
        $config = $this->container->get(Config::class);
        $logger = $this->container->get(LoggerInterface::class);
        if ($logger) {
            $this->setLogger($logger);
        }

        $this->debug = (bool)$config->get(array('route','debug'), false);
        $this->setLogDebug($this->debug);
        $twig = null;
        try {
            $resolved = $this->container->get(Environment::class);
            if ($resolved instanceof Environment) {
                $twig = $resolved;
            }
        } catch (\Throwable $e) {
            // Twig is optional: a headless (e.g. JSON-only) app can run without it.
            $this->error('Twig', array($e->getMessage()));
        }
        $this->twig = $twig;

        if ($this->twig instanceof Environment) {
            $plugin_config = (new ConfigProvider())->getViewHelperConfig();
            if ($config->has('form')) {
                $plugin_config['aliases'] = array_merge(
                    $plugin_config['aliases'],
                    $config->get(array('form', 'aliases'), array())
                );
                $plugin_config['factories'] = array_merge(
                    $plugin_config['factories'],
                    $config->get(array('form', 'factories'), array())
                );
                $plugin_config['initializers'] = array_merge(
                    $plugin_config['initializers'],
                    $config->get(array('form', 'initializers'), array())
                );
            }
            $plugin_config['initializers'][] = function
            (
                $context,
                $object
            ) use ($twig) {
                if ($object instanceof TwigAwareInterface) {
                    $object->setTwig($twig);
                }
            };

            $renderer = new PhpRenderer();
            $plugins = $renderer->getHelperPluginManager();
            $plugins->configure($plugin_config);

            $this->twig->registerUndefinedFunctionCallback(
                function ($name) use ($renderer, $plugins) {
                    if (!$plugins->has($name)) {
                        return false;
                    }

                    $callable = array($renderer->plugin($name), '__invoke');
                    $options = array('is_safe' => array('html'));
                    return new TwigFunction($name, $callable, $options);
                }
            );
        }

        register_shutdown_function(array($this,'_fatal_handler'));
    }

    /**
     * @return ResponseInterface
     */
    public function go()
    {
        $router = $this->container->get(Router::class);
        /** @var Router $router */
        $router->buildRoutes();
        return $router->go();
    }


    public function _fatal_handler(): void
    {
        $error = error_get_last();

        if (!isset($error['type']) || !in_array($error['type'], self::FATAL_ERROR_TYPES, true)) {
            return;
        }

        $this->error(ServerRequest::fromGlobals()->getUri()->getPath(), $error);

        $response = new Response(500);
        $renderer = new ErrorRenderer($this->debug, $this->twig);

        // error_get_last() gives an array, not a throwable, so build one to carry the
        // same information into the renderer.
        $throwable = new \ErrorException(
            (string)($error['message'] ?? 'Fatal error'),
            0,
            (int)$error['type'],
            (string)($error['file'] ?? ''),
            (int)($error['line'] ?? 0)
        );

        $body = $renderer->renderPrettyPage($throwable)
            ?? $renderer->renderTemplate($throwable, 500, $response->getReasonPhrase());

        $response->getBody()->write($body);

        if (ob_get_level()) {
            ob_end_clean();
        }

        Html::emit($response);
    }
}
