<?php

namespace CodeCTRL\Apollo\Http\Strategies;

use Exception;
use League\Route\Http\Exception as HttpException;
use League\Route\Http\Exception\MethodNotAllowedException;
use League\Route\Http\Exception\NotFoundException;
use League\Route\Http\Exception\UnauthorizedException;
use League\Route\Route;
use League\Route\Strategy\ApplicationStrategy;
use CodeCTRL\Apollo\Http\Route\Router;
use CodeCTRL\Apollo\Utility\Logger\Interfaces\LoggerHelperInterface;
use CodeCTRL\Apollo\Utility\Logger\Traits\LoggerHelperTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

class HtmlStrategy extends ApplicationStrategy implements LoggerHelperInterface
{
    use LoggerHelperTrait;

    /**
     * @var string
     */
    private $content_type = 'text/html';

    /**
     * @var \Twig\Environment
     */
    protected $twig;

    /**
     * @var Router
     */
    protected $router;

    /**
     * @param \Twig\Environment $twig
     * @param Router $router
     * @param LoggerInterface|null $logger
     */
    public function __construct(Environment $twig, Router $router, LoggerInterface $logger = null)
    {
        $this->twig = $twig;
        $this->router = $router;
        if ($logger) {
            $this->setLogger($logger);
        }
    }

    public function getContentType(): string
    {
        return $this->content_type;
    }

    public function getTwig(): Environment
    {
        return $this->twig;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function invokeRouteCallable(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        $response = new \Laminas\Diactoros\Response;
        $controller = $route->getCallable($this->getContainer());
        $response = $controller($request, $response, $route->getVars());
        $contentType = $this->getContentType();
        if(!empty($response->getHeaders())) {
            foreach ($response->getHeaders() as $headerKey => $headerVal) {
                if($headerKey == 'Content-Type') {
                    $contentType = $headerVal[0];
                }
            }
        }
        $response = $response->withHeader('Content-Type', $contentType);
        return $this->decorateResponse($response);
    }

    public function getNotFoundDecorator(NotFoundException $exception): MiddlewareInterface
    {
        return $this->buildStandardException(404);
    }

    public function getMethodNotAllowedDecorator(MethodNotAllowedException $exception): MiddlewareInterface
    {
        return $this->buildStandardException(405);
    }

    public function getThrowableHandler(): MiddlewareInterface
    {
        return new class ($this) implements MiddlewareInterface {
            protected $strategy;

            public function __construct(HtmlStrategy $strategy)
            {
                $this->strategy = $strategy;
            }

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler
            ): ResponseInterface
            {
                try {
                    return $handler->handle($request);
                } catch (Exception $exception) {
                    $response = new \Laminas\Diactoros\Response;
                    if ($exception instanceof UnauthorizedException) {
                        $response = $response->withHeader('Location', $this->strategy->getRouter()->getRealUrl($this->strategy->getRouter()->getNamedRoute('login')->getPath()));
                        return $response;
                    }
                    if ($exception instanceof HttpException) {
                        $response = $response->withStatus($exception->getStatusCode());
                        $params = array(
                            'title' => $response->getStatusCode(),
                            'block' => array(
                                'title' => $exception->getMessage(),
                                'content' => json_decode(strtok("\n"), true),
                            ),
                        );
                        $response->getBody()->write($this->strategy->getTwig()->render('errors.html.twig', $params));
                        return $response;
                    }

                    $this->strategy->error('FatalError', (array)$exception->getMessage());
                    $response = $response->withStatus(500);
                    $params = array(
                        'title' => $response->getStatusCode(),
                        'block' => array(
                            'title' => $response->getReasonPhrase(),
                            'message' => $exception->getMessage(),
                            'trace' => $exception->getTraceAsString(),
                        ),
                    );
                    $response->getBody()->write($this->strategy->getTwig()->render('errors.html.twig', $params));
                    return $response;
                }
            }
        };
    }

    /**
     * @param $statusCode
     * @return MiddlewareInterface
     */
    private function buildStandardException($statusCode = 400): MiddlewareInterface
    {
        return new class ($this, $statusCode) implements MiddlewareInterface {

            protected $strategy;
            protected $statusCode;

            public function __construct(HtmlStrategy $strategy, $statusCode)
            {
                $this->strategy = $strategy;
                $this->statusCode = $statusCode;
            }

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler
            ): ResponseInterface
            {
                $response = new \Laminas\Diactoros\Response;
                $response = $response->withStatus($this->statusCode);
                $params = array(
                    'title' => $response->getStatusCode(),
                    'block' => array(
                        'title' => $response->getReasonPhrase(),
                    ),
                );
                $response->getBody()->write($this->strategy->getTwig()->render('errors.html.twig', $params));
                return $response;
            }
        };
    }
}
