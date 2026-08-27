<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Http\Middlewares;

use CodeCTRL\Apollo\Security\CSRF;
use League\Route\Http\Exception\ForbiddenException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects state changing requests that do not carry a valid CSRF token.
 *
 * Until 3.3.0 CSRF verification was opt-in per form, so forgetting the call left an
 * endpoint unprotected with nothing to signal it. Laravel puts VerifyCsrfToken in the
 * whole "web" middleware group for the same reason; register this in the `web` group
 * (see the middleware config) and endpoints are covered unless deliberately excluded.
 *
 * The token is read, in order, from the request body (`_csrf`, or the legacy `token`),
 * the merged query parameters, and the X-CSRF-Token header — the last one so that XHR
 * callers do not have to rewrite their payloads.
 *
 * Options:
 *   csrf_form   string  Form id the token belongs to. Defaults to the route name, then
 *                       to the submitted `_csrf_form` field, then to a shared id.
 *   csrf_except array   Paths to skip, e.g. stateless webhook receivers that
 *                       authenticate by signature instead.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const PROTECTED_METHODS = array('POST', 'PUT', 'PATCH', 'DELETE');

    private const HEADER = 'X-CSRF-Token';

    private const SHARED_FORM_ID = '_apollo_shared';

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private array $options = array())
    {
    }

    /**
     * {@inheritdoc}
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), self::PROTECTED_METHODS, true)) {
            return $handler->handle($request);
        }

        if ($this->isExcepted($request)) {
            return $handler->handle($request);
        }

        $data = array_merge((array)$request->getQueryParams(), (array)$request->getParsedBody());
        $token = CSRF::tokenFrom($data);

        if ($token === null && $request->hasHeader(self::HEADER)) {
            $header = trim($request->getHeaderLine(self::HEADER));
            $token = $header === '' ? null : $header;
        }

        if (!CSRF::verifyToken($this->formId($request, $data), $token)) {
            throw new ForbiddenException('Invalid or missing CSRF token.');
        }

        return $handler->handle($request);
    }

    /**
     * @param ServerRequestInterface $request
     * @return bool
     */
    private function isExcepted(ServerRequestInterface $request): bool
    {
        $except = (array)($this->options['csrf_except'] ?? array());
        if (empty($except)) {
            return false;
        }

        $path = '/' . trim($request->getUri()->getPath(), '/');

        foreach ($except as $pattern) {
            $pattern = '/' . trim((string)$pattern, '/');
            if ($pattern === $path || fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ServerRequestInterface $request
     * @param array<string, mixed> $data
     * @return string
     */
    private function formId(ServerRequestInterface $request, array $data): string
    {
        if (!empty($this->options['csrf_form'])) {
            return (string)$this->options['csrf_form'];
        }

        if (!empty($this->options['name'])) {
            return (string)$this->options['name'];
        }

        if (isset($data['_csrf_form']) && is_string($data['_csrf_form']) && $data['_csrf_form'] !== '') {
            return $data['_csrf_form'];
        }

        $routeName = $request->getAttribute('route_name');

        return is_string($routeName) && $routeName !== '' ? $routeName : self::SHARED_FORM_ID;
    }
}
