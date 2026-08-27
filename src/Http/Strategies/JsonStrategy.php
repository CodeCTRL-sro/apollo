<?php

namespace CodeCTRL\Apollo\Http\Strategies;

use League\Route\Http\Exception as HttpException;
use League\Route\Http\Exception\MethodNotAllowedException;
use League\Route\Http\Exception\NotFoundException;
use League\Route\Http\Exception\UnauthorizedException;
use League\Route\Route;
use League\Route\Strategy\ApplicationStrategy;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Http\Route\Router;
use CodeCTRL\Apollo\Utility\Debug\ErrorRenderer;
use CodeCTRL\Apollo\Utility\Logger\Interfaces\LoggerHelperInterface;
use CodeCTRL\Apollo\Utility\Logger\Traits\LoggerHelperTrait;
use CodeCTRL\Apollo\Utility\Utils\APIResponseBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Twig\Environment;

class JsonStrategy extends ApplicationStrategy implements LoggerHelperInterface
{
    use LoggerHelperTrait;

    /**
     * @var string
     */
    protected $content_type = 'application/json';

    /**
     * @var \Twig\Environment
     */
    protected $twig;

    /**
     * @var Router
     */
    protected $router;

    /**
     * @var ErrorRenderer
     */
    protected $errorRenderer;

    /**
     * @param \Twig\Environment $twig
     * @param Router $router
     * @param LoggerInterface|null $logger
     * @param Config|null $config Supplies route.debug; optional so the previous
     *                            three-argument construction keeps working.
     */
    public function __construct(Environment $twig, Router $router, LoggerInterface $logger = null, Config $config = null)
    {
        $this->twig = $twig;
        $this->router = $router;
        if ($logger) {
            $this->setLogger($logger);
        }

        $debug = (bool)($config?->get(array('route', 'debug'), false) ?? false);
        $this->setLogDebug($debug);
        $this->errorRenderer = new ErrorRenderer($debug, $twig);
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

    public function getErrorRenderer(): ErrorRenderer
    {
        return $this->errorRenderer;
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
        return $this->buildStandardException(404, "Method not found");
    }

    public function getMethodNotAllowedDecorator(MethodNotAllowedException $exception): MiddlewareInterface
    {
        return $this->buildStandardException(405, "Method not allowed");
    }

    public function getThrowableHandler(): MiddlewareInterface
    {
        return new class ($this) implements MiddlewareInterface {
            protected $strategy;

            public function __construct(JsonStrategy $strategy)
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
                    // Throwable, not Exception: an Error (TypeError, ValueError, ...) is
                    // not an Exception and used to bypass this handler completely,
                    // leaving the client with whatever the shutdown function emitted.
                } catch (Throwable $exception) {
                    $renderer = $this->strategy->getErrorRenderer();
                    $response = new \Laminas\Diactoros\Response;

                    if ($exception instanceof UnauthorizedException) {
                        return $this->respond($response, new APIResponseBuilder(401, 'Unauthorized'));
                    }

                    if ($exception instanceof HttpException) {
                        $message = $exception->getMessage();
                        $data = array();

                        $decoded = json_decode($message, true);
                        if (is_array($decoded)) {
                            $message = isset($decoded['message']) ? (string)$decoded['message'] : $message;
                            $data = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : array();
                        }

                        $builder = new APIResponseBuilder($exception->getStatusCode(), $message, $data);

                        return $this->respond($response, $builder, $exception->getHeaders());
                    }

                    $this->strategy->error('FatalError', array(
                        'message' => $exception->getMessage(),
                        'file' => $exception->getFile() . ':' . $exception->getLine(),
                        'trace' => $exception->getTraceAsString(),
                    ));

                    $response = $response->withStatus(500);
                    // Outside debug mode the payload is the status and reason phrase and
                    // nothing else: the exception message often names a table, a query or
                    // a filesystem path.
                    $builder = new APIResponseBuilder(
                        500,
                        $response->getReasonPhrase(),
                        $renderer->jsonDebugData($exception)
                    );

                    return $this->respond($response, $builder);
                }
            }

            /**
             * @param ResponseInterface $response
             * @param APIResponseBuilder $builder
             * @param array<string, string> $headers
             * @return ResponseInterface
             */
            private function respond(ResponseInterface $response, APIResponseBuilder $builder, array $headers = array()): ResponseInterface
            {
                $response->getBody()->write($builder->build());
                $response = $response
                    ->withHeader('Content-type', $this->strategy->getContentType())
                    ->withStatus($builder->getStatus());

                foreach ($headers as $name => $value) {
                    $response = $response->withHeader((string)$name, (string)$value);
                }

                return $response;
            }
        };
    }

    /**
     * @param int $statusCode
     * @param string|null $message
     * @return MiddlewareInterface
     */
    private function buildStandardException($statusCode = 400, $message = null): MiddlewareInterface
    {
        return new class ($this, $statusCode, $message) implements MiddlewareInterface {
            protected $strategy;
            protected $statusCode;
            protected $message;

            public function __construct(JsonStrategy $strategy, $statusCode, $message)
            {
                $this->strategy = $strategy;
                $this->statusCode = $statusCode;
                $this->message = $message;
            }

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler
            ): ResponseInterface
            {
                $response = new \Laminas\Diactoros\Response;
                $response = $response->withStatus($this->statusCode);
                if ($this->message != null) {
                    $apiResponseBuilder = new APIResponseBuilder($this->statusCode, $this->message);
                } else {
                    $apiResponseBuilder = new APIResponseBuilder($response->getStatusCode(), $response->getReasonPhrase());
                }
                $response->getBody()->write($apiResponseBuilder->build());
                return $response->withHeader('Content-type', $this->strategy->getContentType())->withStatus($apiResponseBuilder->getStatus());
            }
        };
    }
}
