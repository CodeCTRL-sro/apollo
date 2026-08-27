<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Tests\Unit\Http;

use CodeCTRL\Apollo\Http\Middlewares\SecurityHeadersMiddleware;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(SecurityHeadersMiddleware::class)]
final class SecurityHeadersMiddlewareTest extends TestCase
{
    private function handler(ResponseInterface $response = null): RequestHandlerInterface
    {
        $response ??= new Response();

        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    private function request(string $uri = 'http://example.test/'): ServerRequestInterface
    {
        return new ServerRequest(uri: new Uri($uri), method: 'GET');
    }

    public function testDefaultHeadersAreAdded(): void
    {
        $response = (new SecurityHeadersMiddleware())->process($this->request(), $this->handler());

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testCspIsNotSentUnlessConfigured(): void
    {
        $response = (new SecurityHeadersMiddleware())->process($this->request(), $this->handler());

        self::assertFalse($response->hasHeader('Content-Security-Policy'));
    }

    public function testConfiguredHeadersOverrideTheDefaults(): void
    {
        $middleware = new SecurityHeadersMiddleware(array(
            'security_headers' => array(
                'X-Frame-Options' => 'DENY',
                'Content-Security-Policy' => "default-src 'self'",
            ),
        ));

        $response = $middleware->process($this->request(), $this->handler());

        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    /**
     * A controller has to be able to opt one response out.
     */
    public function testAHeaderTheResponseAlreadyCarriesIsLeftAlone(): void
    {
        $existing = (new Response())->withHeader('X-Frame-Options', 'ALLOW-FROM https://partner.test');

        $response = (new SecurityHeadersMiddleware())->process($this->request(), $this->handler($existing));

        self::assertSame('ALLOW-FROM https://partner.test', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testFalseRemovesAHeader(): void
    {
        $existing = (new Response())->withHeader('X-Powered-By', 'PHP/8.3');

        $middleware = new SecurityHeadersMiddleware(array('X-Powered-By' => false));
        $response = $middleware->process($this->request(), $this->handler($existing));

        self::assertFalse($response->hasHeader('X-Powered-By'));
    }

    /**
     * Sending HSTS from a plain HTTP staging environment is how a domain locks itself
     * out; browsers ignore it there anyway.
     */
    public function testHstsIsSkippedOverPlainHttp(): void
    {
        $middleware = new SecurityHeadersMiddleware(array(
            'Strict-Transport-Security' => 'max-age=31536000',
        ));

        $response = $middleware->process($this->request('http://example.test/'), $this->handler());

        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function testHstsIsSentOverHttps(): void
    {
        $middleware = new SecurityHeadersMiddleware(array(
            'Strict-Transport-Security' => 'max-age=31536000',
        ));

        $response = $middleware->process($this->request('https://example.test/'), $this->handler());

        self::assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
    }
}
