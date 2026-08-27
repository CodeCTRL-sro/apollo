<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Http\Exception;

use League\Route\Http\Exception\TooManyRequestsException;

/**
 * A 429 that carries Retry-After and the X-RateLimit-* headers.
 *
 * League's TooManyRequestsException hardcodes an empty header array in its constructor,
 * so there is no way to attach them to it. Subclassing keeps `instanceof
 * TooManyRequestsException` true for any application already catching that type.
 */
final class RateLimitExceededException extends TooManyRequestsException
{
    /**
     * @param string $message
     * @param array<string, string> $headers
     */
    public function __construct(string $message = 'Too Many Requests', array $headers = array())
    {
        parent::__construct($message);
        $this->headers = $headers;
    }
}
