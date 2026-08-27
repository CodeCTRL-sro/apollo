<?php

namespace CodeCTRL\Apollo\UI\Html;

use DOMDocument;
use GuzzleHttp\Psr7\Header;
use InvalidArgumentException;
use Laminas\HttpHandlerRunner\Emitter\SapiStreamEmitter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Html
{

    /**
     * @param ServerRequestInterface $request
     * @param $base_path
     * @return ServerRequestInterface
     */
    public static function removePathPrefix(ServerRequestInterface $request, $base_path)
    {
        $path_prefix = trim($base_path, '/');
        if (!empty($path_prefix)) {
            $uri = $request->getUri();
            $path = trim($uri->getPath(), '/');
            $path_parts = explode('/', $path);
            $prefix_parts = explode('/', $path_prefix);
            do {
                if (empty($prefix_parts) || empty($path_parts) || $prefix_parts[0] != $path_parts[0]) {
                    break;
                }
                array_shift($path_parts);
                array_shift($prefix_parts);
            } while (true);
            $path = '/' . implode('/', $path_parts);
            $uri = $uri->withPath($path);
            $request = $request->withUri($uri, true);
        }
        return $request;
    }

    /**
     * @param ServerRequestInterface $serverRequest
     * @throws InvalidArgumentException
     */
    public static function parseRequest(ServerRequestInterface &$serverRequest)
    {
        $method = $serverRequest->getMethod();
        if(in_array($method,array('POST','PUT','PATCH','DELETE'))){
            $contentType = Html::getContentType($serverRequest);
            switch ($contentType) {
                case 'application/x-www-form-urlencoded':
                    if ($method != 'POST') {
                        parse_str($serverRequest->getBody()->getContents(), $body);
                        $serverRequest = $serverRequest->withParsedBody($body);
                    }
                    break;
                case 'application/json':
                    $serverRequest = $serverRequest->withParsedBody(Html::getJsonBody($serverRequest));
                    break;
                case 'application/xml':
                    $serverRequest = $serverRequest->withParsedBody(Html::getXmlBody($serverRequest));
                    break;
            }
            $serverRequest = $serverRequest->withQueryParams(array_merge($serverRequest->getQueryParams(), (array)$serverRequest->getParsedBody()));
        }
    }

    /**
     * Send a response to the client.
     *
     * laminas/laminas-httphandlerrunner has been a dependency all along but was never
     * used; its emitter gets the details right that the hand rolled version here did
     * not — Set-Cookie is sent as repeated headers instead of being folded into one
     * comma separated line, the body is streamed in chunks rather than materialised in
     * memory, and Content-Range is honoured.
     *
     * @param ResponseInterface $response
     * @return bool True when the response was emitted.
     */
    public static function emit(ResponseInterface $response): bool
    {
        if (headers_sent()) {
            // Nothing can be done about the status line at this point, but the body is
            // still worth sending.
            echo (string)$response->getBody();

            return false;
        }

        try {
            return (new SapiStreamEmitter())->emit($response);
        } catch (\Throwable $e) {
            return self::emitManually($response);
        }
    }

    /**
     * @param ResponseInterface $response
     * @return string Always empty; the response has already been written out.
     * @deprecated 3.3.0 Use emit(). This used to send only the headers and hand the body
     *             back for the caller to echo, so `echo Html::response($r)` keeps
     *             working — but the body now goes out through the emitter and the return
     *             value is an empty string rather than the body stream.
     */
    public static function response(ResponseInterface $response)
    {
        self::emit($response);

        return '';
    }

    /**
     * @param ResponseInterface $response
     * @return bool
     */
    private static function emitManually(ResponseInterface $response): bool
    {
        header(sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        ), true, $response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            $first = strtolower($name) !== 'set-cookie';
            foreach ($values as $value) {
                header("{$name}: {$value}", $first);
                $first = false;
            }
        }

        echo (string)$response->getBody();

        return true;
    }

    /**
     * @param ServerRequestInterface $serverRequest
     * @return mixed
     */
    public static function getContentType(ServerRequestInterface $serverRequest)
    {
        $contentType = Header::parse($serverRequest->getHeader('Content-Type'));
        return empty($contentType[0][0]) ? '' : $contentType[0][0];
    }

    /**
     * @param ServerRequestInterface $serverRequest
     * @throws InvalidArgumentException
     * @return array|null
     */
    public static function getJsonBody(ServerRequestInterface $serverRequest)
    {
        $body = json_decode($serverRequest->getBody(), true);
        if (!$body && json_last_error()) {
            throw new InvalidArgumentException();
        }
        return $body;
    }

    /**
     * @param ServerRequestInterface $serverRequest
     * @throws InvalidArgumentException
     * @return DOMDocument|null
     */
    public static function getXmlBody(ServerRequestInterface $serverRequest)
    {
        $body = new DOMDocument();
        $body->loadXML($serverRequest->getBody());
        if ($body === false) {
            throw new InvalidArgumentException();
        }
        return $body;
    }
}
