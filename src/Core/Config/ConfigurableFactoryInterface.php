<?php

namespace CodeCTRL\Apollo\Core\Config;

interface ConfigurableFactoryInterface
{
    /**
     * @param Config $config
     * @return mixed
     */
    public function configure(Config $config);
}
