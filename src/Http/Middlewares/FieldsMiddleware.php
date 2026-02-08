<?php

namespace CodeCTRL\Apollo\Http\Middlewares;

use Doctrine\ORM\EntityManagerInterface;
use League\Container\Container;
use League\Route\Http\Exception\BadRequestException;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Security\Auth\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class FieldsMiddleware implements MiddlewareInterface
{
    /**
     * @var array
     */
    protected $options;

    /**
     * @var Auth
     */
    protected $auth;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var EntityManagerInterface|null
     */
    protected $entityManager;

    /**
     * @var Container
     */
    protected $container;


    public function __construct($options, Config $config, Container $container, EntityManagerInterface $em = null)
    {
        $this->options = $options;
        $this->auth = new Auth($config, $em);
        $this->config = $config;
        $this->entityManager = $em;
        $this->container = $container;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $required_fields = $this->options['required_fields'];
        $params = $request->getQueryParams();
        $errors = array();
        foreach ($required_fields as $field => $fieldOptions) {
            if (is_array($fieldOptions)) {
                if (!isset($params[$field]) || (is_array($params[$field]) && empty($params[$field])) || (!is_array($params[$field]) && $params[$field] == '')) {
                    $errors[$field][] = 'required';
                } else {
                    if (isset($fieldOptions["min"])) {
                        if (mb_strlen($params[$field]) < $fieldOptions["min"]) {
                            $errors[$field][] = $field . '_min_' . $fieldOptions["min"];
                        }
                    }
                    if (isset($fieldOptions["max"])) {
                        if (mb_strlen($params[$field]) > $fieldOptions["max"]) {
                            $errors[$field][] = $field . '_max_' . $fieldOptions["max"];
                        }
                    }
                    if (isset($fieldOptions["custom"])) {
                        if (!empty($fieldOptions["custom"])) {
                            $class = $fieldOptions["custom"][0];
                            $method = $fieldOptions["custom"][1];
                            $invokableClass = $this->container->get($fieldOptions["custom"][0]);
                            if (method_exists($class, $method)) {
                                $value = $params[$field];
                                $customValidateResponse = $invokableClass->$method($value,$field, $fieldOptions);
                                if ($customValidateResponse != null) {
                                    $errors[$field][] = $customValidateResponse;
                                }
                            }
                        }
                    }
                }
            } else {
                $field = $fieldOptions;
                if (!isset($params[$field]) || (is_array($params[$field]) && empty($params[$field])) || (!is_array($params[$field]) && $params[$field] == '')) {
                    $errors[$field][] = 'required';
                }
            }
        }
        if (!empty($errors)) {
            throw new BadRequestException(json_encode(array('message' => 'bad_request', 'data' => $errors)));
        }
        return $handler->handle($request);
    }

}