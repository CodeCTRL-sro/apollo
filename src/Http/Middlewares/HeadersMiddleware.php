<?php

namespace CodeCTRL\Apollo\Http\Middlewares;

use Doctrine\ORM\EntityManagerInterface;
use League\Route\Http\Exception\BadRequestException;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Security\Auth\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class HeadersMiddleware implements MiddlewareInterface
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
     * @var EntityManagerInterface
     */
    protected $entityManager;


    public function __construct($options, Config $config, EntityManagerInterface $em = null)
    {
        $this->options = $options;
        $this->auth = new Auth($config, $em);
        $this->config = $config;
        $this->entityManager = $em;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $required_headers = array_unique($this->options['required_headers']);
        $errors = array();
        foreach ($required_headers as $required_header) {
            $header = $request->getHeaderLine($required_header);
            if (!$header) {
                $errors[] = $required_header;
            }
        }
        if (!empty($errors)) {
            throw new BadRequestException(json_encode(array('message' => 'bad_request', 'data' => $errors)));
        }
        return $handler->handle($request);
    }

}