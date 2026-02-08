<?php
namespace CodeCTRL\Apollo\Database\Doctrine;

use Exception;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Core\Config\ConfigurableFactoryInterface;
use CodeCTRL\Apollo\Core\Config\ConfigurableFactoryTrait;
use CodeCTRL\Apollo\Core\Factory\InvokableFactoryInterface;

class TablePrefixFactory implements InvokableFactoryInterface, ConfigurableFactoryInterface
{
    use ConfigurableFactoryTrait;

    /**
     * @return TablePrefix
     * @throws Exception
     */
    public function __invoke()
    {
        if (!$this->config instanceof Config) {
            throw new Exception(__CLASS__ . " can't work without configuration");
        }

        return new TablePrefix(
            $this->config->get('prefix', ''),
            $this->config->get('prefix_namespaces', array())
        );
    }
}
