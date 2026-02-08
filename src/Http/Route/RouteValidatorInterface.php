<?php

namespace CodeCTRL\Apollo\Http\Route;

use League\Container\Container;
use League\Route\Route;

interface RouteValidatorInterface
{
    /**
     * @param Route $map
     * @param array $requires
     * @param array $options
     * @param Container $container
     * @return Route
     */
    public function validate(Route $map, array $requires, array $options, Container $container);
}
