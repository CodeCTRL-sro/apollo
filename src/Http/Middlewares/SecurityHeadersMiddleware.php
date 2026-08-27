<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Http\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds the response headers a browser needs in order to enforce anything on our behalf.
 *
 * Symfony ships these through nelmio/security-bundle and Laravel through its default
 * middleware; Apollo sent none of them, which left MIME sniffing, framing and referrer
 * leakage entirely to browser defaults.
 *
 * Only the three headers that are safe everywhere are on by default. CSP, HSTS and
 * Permissions-Policy stay opt-in, because a default value for any of them breaks real
 * applications (inline scripts, plain HTTP staging, camera/geolocation features) and a
 * security header that gets switched off wholesale is worse than one that was never on.
 *
 * A header the application already set is never overwritten, so a controller can still
 * opt a single response out.
 *
 * Config example:
 *
 *     'security_headers' => array(
 *         'X-Frame-Options'           => 'DENY',
 *         'Content-Security-Policy'   => "default-src 'self'",
 *         'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
 *         'Permissions-Policy'        => 'geolocation=(), camera=()',
 *         'X-Powered-By'              => false,   // false removes the header
 *     ),
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @var array<string, string>
     */
    private const DEFAULTS = array(
        // Stops the browser second-guessing Content-Type, which is what turns an
        // uploaded "image" into executable script.
        'X-Content-Type-Options' => 'nosniff',
        // Full URLs (with their query strings) stop leaking to third party origins.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        // Clickjacking. SAMEORIGIN rather than DENY so in-app iframes keep working.
        'X-Frame-Options' => 'SAMEORIGIN',
    );

    /**
     * @var array<string, string|false>
     */
    private array $headers;

    /**
     * @param array<string, mixed> $options Either the header map itself, or an array
     *                                      with a 'security_headers' key holding it.
     */
    public function __construct(array $options = array())
    {
        /** @var array<string, string|false> $configured */
        $configured = isset($options['security_headers']) && is_array($options['security_headers'])
            ? $options['security_headers']
            : $options;

        $this->headers = array_merge(self::DEFAULTS, $configured);
    }

    /**
     * {@inheritdoc}
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $isSecure = $this->isSecure($request);

        foreach ($this->headers as $name => $value) {
            if ($value === false || $value === null || $value === '') {
                $response = $response->withoutHeader((string)$name);
                continue;
            }

            // HSTS over plain HTTP is ignored by browsers, and setting it from a
            // non-TLS response is how a staging environment locks itself out.
            if (strcasecmp((string)$name, 'Strict-Transport-Security') === 0 && !$isSecure) {
                continue;
            }

            if ($response->hasHeader((string)$name)) {
                continue;
            }

            $response = $response->withHeader((string)$name, (string)$value);
        }

        return $response;
    }

    /**
     * @param ServerRequestInterface $request
     * @return bool
     */
    private function isSecure(ServerRequestInterface $request): bool
    {
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }

        $server = $request->getServerParams();

        return !empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off';
    }
}
