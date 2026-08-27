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
use CodeCTRL\Apollo\Security\RedirectGuard;
use CodeCTRL\Apollo\Utility\Debug\ErrorRenderer;
use CodeCTRL\Apollo\Utility\Logger\Interfaces\LoggerHelperInterface;
use CodeCTRL\Apollo\Utility\Logger\Traits\LoggerHelperTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
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
                    // Throwable, not Exception: TypeError, ValueError and every other
                    // Error is not an Exception, so those used to escape this handler
                    // entirely and surface from the shutdown function, long after a
                    // proper response could still be produced.
                } catch (Throwable $exception) {
                    $renderer = $this->strategy->getErrorRenderer();
                    $response = new \Laminas\Diactoros\Response;

                    if ($exception instanceof UnauthorizedException) {
                        $router = $this->strategy->getRouter();
                        $loginUrl = $router->getRealUrl($router->getNamedRoute('login')->getPath());
                        $intended = $router->getIntendedUrl(array($loginUrl));
                        return $response
                            ->withStatus(302)
                            ->withHeader('Location', RedirectGuard::appendTo($loginUrl, $intended));
                    }

                    if ($exception instanceof HttpException) {
                        $response = $response->withStatus($exception->getStatusCode());
                        // Carries Retry-After / X-RateLimit-* and anything else an
                        // exception attached; these were silently dropped before.
                        foreach ($exception->getHeaders() as $name => $value) {
                            $response = $response->withHeader((string)$name, (string)$value);
                        }

                        $params = $renderer->templateParams(
                            $exception,
                            $response->getStatusCode(),
                            $exception->getMessage() ?: $response->getReasonPhrase()
                        );
                        $params['block']['content'] = self::decodePayload($exception->getMessage());

                        $response->getBody()->write($this->strategy->renderErrorTemplate($params));
                        return $response;
                    }

                    $this->strategy->error('FatalError', array(
                        'message' => $exception->getMessage(),
                        'file' => $exception->getFile() . ':' . $exception->getLine(),
                        'trace' => $exception->getTraceAsString(),
                    ));

                    $response = $response->withStatus(500);

                    $pretty = $renderer->renderPrettyPage($exception);
                    if ($pretty !== null) {
                        $response->getBody()->write($pretty);
                        return $response;
                    }

                    $response->getBody()->write($renderer->renderTemplate(
                        $exception,
                        500,
                        $response->getReasonPhrase()
                    ));
                    return $response;
                }
            }

            /**
             * Middlewares signal field errors by throwing an HttpException whose message
             * is a JSON document. Decode it when that is what it is, otherwise leave the
             * message alone.
             *
             * @param string $message
             * @return array|string|null
             */
            private static function decodePayload(string $message)
            {
                $decoded = json_decode($message, true);

                return is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;
            }
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return string
     */
    public function renderErrorTemplate(array $params): string
    {
        try {
            return $this->twig->render('errors.html.twig', $params);
        } catch (Throwable $e) {
            $this->error('Twig', array($e->getMessage()));

            return $this->errorRenderer->fallbackPage(
                (int)($params['title'] ?? 500),
                (string)($params['block']['title'] ?? 'Error')
            );
        }
    }

    /**
     * @param int $statusCode
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
                $response->getBody()->write($this->strategy->renderErrorTemplate($params));
                return $response;
            }
        };
    }
}
