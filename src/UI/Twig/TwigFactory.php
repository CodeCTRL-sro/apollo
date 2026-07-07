<?php

namespace CodeCTRL\Apollo\UI\Twig;

use Exception;
use League\Container\ContainerAwareInterface;
use League\Container\ContainerAwareTrait;
use CodeCTRL\Apollo\Core\Config\ConfigurableFactoryInterface;
use CodeCTRL\Apollo\Core\Config\ConfigurableFactoryTrait;
use CodeCTRL\Apollo\Core\Factory\InvokableFactoryInterface;
use CodeCTRL\Apollo\Utility\Logger\Logger;
use Twig\Extension\AbstractExtension;
use Twig\Extension\DebugExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;

class TwigFactory implements InvokableFactoryInterface, ConfigurableFactoryInterface, ContainerAwareInterface
{
    use ConfigurableFactoryTrait;
    use ContainerAwareTrait;

    /**
     * @return Twig
     * @throws Exception
     */
    public function __invoke()
    {
        $logger = new Logger('TWIG');

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $uriParts = explode("/", $requestUri);
        $findUrlBasepath = $uriParts[1] ?? '';

        // Without configuration Twig can still run "headless" (e.g. a JSON-only app
        // that never renders a template) using an in-memory loader. This keeps the
        // framework usable without a Twig/templates setup instead of hard-failing.
        if (null == $this->config) {
            return new Twig(new ArrayLoader(), array('autoescape' => false));
        }

        $loader = new FilesystemLoader($this->config->get('templates_path', BASE_DIR . '/src/templates'));
        $paths = $this->config->get('paths', array());
        if (!empty($paths)) {
            foreach ($paths as $module => $module_paths) {
                if(isset($module_paths[$findUrlBasepath])){
                    $loader->addPath($module_paths[$findUrlBasepath], $module);
                    continue;
                }
                foreach ($module_paths as $pathKey => $path) {
                    try {
                        $loader->addPath($path, $module);
                    } catch (Exception $e) {
                        $logger->error('Path not found', array($path));
                    }
                }
            }
        }
        $options = array(
            'debug' => $this->config->get('debug', false),
            'cache' => $this->config->get('cache', false),
            'autoescape' => $this->config->get('autoescape', false),
        );

        $twig = new Twig($loader, $options);
        $twig->setLogDebug($this->config->get('debug', false));
        if ($logger) {
            $twig->setLogger($logger);
        }


        $globals = $this->config->get('globals', array());
        if (!empty($globals)) {
            foreach ($globals as $name => $value) {
                $twig->addGlobal($name, $value);
            }
        }
        if ($twig->isDebug()) {
            $twig->addExtension(new DebugExtension());
        }

        $extensions = $this->config->get('extensions', array());
        if (!empty($extensions)) {
            foreach ($extensions as $extension) {
                try {
                    $twig_extension = $this->container->get($extension);
                    if ($twig_extension instanceof AbstractExtension) {
                        $twig->addExtension($twig_extension);
                    } else {
                        $twig->error('Twig::addExtension', (array)(get_class($twig_extension) . " MUST implement Twig_Extension"));
                    }
                } catch (Exception $e) {
                    $twig->error('Twig::addExtension', (array)$e->getMessage());
                }
            }
        }
        return $twig;
    }
}
