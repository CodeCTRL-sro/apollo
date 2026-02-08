<?php

namespace CodeCTRL\Apollo\UI\Form\View\Helper;


use Laminas\View\Renderer\PhpRenderer;
use CodeCTRL\Apollo\Core\Factory\Factory;
use CodeCTRL\Apollo\UI\Form\ConfigProvider;

class ElementRender
{
    public function render($element)
    {
        $config = Factory::fromNames(array('form'), true);
        $plugin_config = (new ConfigProvider())->getViewHelperConfig();
        $plugin_config['aliases'] = array_merge($plugin_config['aliases'], $config->get(array('form', 'aliases'), array()));
        $plugin_config['factories'] = array_merge($plugin_config['factories'], $config->get(array('form', 'factories'), array()));

        $renderer = new PhpRenderer();
        $plugins = $renderer->getHelperPluginManager();
        $plugins->configure($plugin_config);

        $callable = array($renderer->plugin('FormRow'), '__invoke');
        return $callable($element);
    }
}
