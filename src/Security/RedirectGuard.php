<?php

namespace CodeCTRL\Apollo\Security;

use Psr\Http\Message\ServerRequestInterface;

class RedirectGuard
{
    const PARAM = 'redirect';

    /**
     * @param ServerRequestInterface|null $request
     * @param string $basepath
     * @param array $deny
     * @return string|null
     */
    public static function fromRequest(?ServerRequestInterface $request, string $basepath = '/', array $deny = array()): ?string
    {
        if (!$request instanceof ServerRequestInterface || $request->getMethod() !== 'GET') {
            return null;
        }

        $uri = $request->getUri();
        $path = '/' . ltrim($uri->getPath(), '/');
        $url = rtrim($basepath, '/') . ($path === '/' ? '' : $path);
        if ($url === '') {
            $url = '/';
        }
        $query = $uri->getQuery();

        return self::sanitize($query !== '' ? $url . '?' . $query : $url, $basepath, $deny);
    }

    /**
     * @param string|null $url
     * @param string $basepath
     * @param array $deny
     * @return string|null
     */
    public static function sanitize(?string $url, string $basepath = '/', array $deny = array()): ?string
    {
        if (empty($url) || strlen($url) > 2048) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }
        if ($url[0] !== '/' || str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return null;
        }
        if (parse_url($url, PHP_URL_SCHEME) !== null || parse_url($url, PHP_URL_HOST) !== null) {
            return null;
        }

        $base = rtrim($basepath, '/');
        if ($base !== '' && $url !== $base && !str_starts_with($url, $base . '/')) {
            return null;
        }

        $path = rtrim(explode('?', $url, 2)[0], '/');
        foreach ($deny as $denied) {
            if (rtrim(explode('?', (string)$denied, 2)[0], '/') === $path) {
                return null;
            }
        }

        return $url;
    }

    /**
     * @param string $url
     * @param string|null $intended
     * @return string
     */
    public static function appendTo(string $url, ?string $intended): string
    {
        if ($intended === null) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query(array(self::PARAM => $intended));
    }
}
