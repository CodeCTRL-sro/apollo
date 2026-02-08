<?php

namespace CodeCTRL\Apollo\Http\Middlewares;

use Doctrine\ORM\EntityManagerInterface;
use League\Route\Http\Exception\ForbiddenException;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Security\Auth\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PermissionMiddleware implements MiddlewareInterface
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
     * @var object
     */
    protected $user;


    public function __construct($options, Config $config, $user, EntityManagerInterface $em = null)
    {
        $this->options = $options;
        $this->auth = new Auth($config, $em);
        $this->config = $config;
        $this->entityManager = $em;
        $this->user = $user;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $sessionUser = $this->user;

        foreach ($this->options['require_permissions'] as $require_permission) {
            list($module, $right) = $require_permission;
            if (!$sessionUser || !$sessionUser->hasPermission($module, $right)) {
                throw new ForbiddenException();
            }
        }
        return $handler->handle($request);
    }

}