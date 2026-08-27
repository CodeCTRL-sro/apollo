<?php

namespace CodeCTRL\Apollo\Utility\Helper;

class RemoteAddressHelper
{
    protected bool $useProxy = false;
    protected array $trustedProxies = [];
    protected string $proxyHeader = 'HTTP_X_FORWARDED_FOR';

    /**
     * Build a helper from the `routing` config dimension.
     *
     * Proxy headers are only honoured when trusted_proxies is non-empty: X-Forwarded-For
     * is client-supplied, so trusting it unconditionally lets anyone claim any address —
     * which defeats IP based rate limiting and poisons audit logs. Symfony's
     * framework.trusted_proxies makes the same requirement.
     *
     *     'routing' => array(
     *         'trusted_proxies' => array('10.0.0.1', '10.0.0.2'),
     *         'proxy_header'    => 'X-Forwarded-For',
     *     ),
     *
     * @param array<string, mixed> $options
     * @return static
     */
    public static function fromOptions(array $options): static
    {
        $helper = new static();

        $proxies = array_values(array_filter(array_map('trim', (array)($options['trusted_proxies'] ?? array()))));
        if (!empty($proxies)) {
            $helper->setUseProxy(true)->setTrustedProxies($proxies);
        }

        if (!empty($options['proxy_header'])) {
            $helper->setProxyHeader((string)$options['proxy_header']);
        }

        return $helper;
    }

    /**
     * @param bool $useProxy
     * @return $this
     */
    public function setUseProxy(bool $useProxy = true): static
    {
        $this->useProxy = $useProxy;
        return $this;
    }

    /**
     * @return bool
     */
    public function getUseProxy(): bool
    {
        return $this->useProxy;
    }

    /**
     * @param array $trustedProxies
     * @return $this
     */
    public function setTrustedProxies(array $trustedProxies): static
    {
        $this->trustedProxies = $trustedProxies;
        return $this;
    }

    /**
     * @param string $header
     * @return $this
     */
    public function setProxyHeader(string $header = 'X-Forwarded-For'): static
    {
        $this->proxyHeader = $this->normalizeProxyHeader($header);
        return $this;
    }

    /**
     * @return string
     */
    public function getIpAddress(): string
    {
        $ip = $this->getIpAddressFromProxy();
        if ($ip) {
            return $ip;
        }
        if (isset($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return '';
    }

    /**
     * @return false|string
     */
    protected function getIpAddressFromProxy(): false|string
    {
        if (!$this->useProxy
            || (isset($_SERVER['REMOTE_ADDR']) && !in_array($_SERVER['REMOTE_ADDR'], $this->trustedProxies))
        ) {
            return false;
        }

        $header = $this->proxyHeader;
        if (!isset($_SERVER[$header]) || empty($_SERVER[$header])) {
            return false;
        }

        $ips = explode(',', $_SERVER[$header]);
        $ips = array_map('trim', $ips);
        $ips = array_diff($ips, $this->trustedProxies);

        if (empty($ips)) {
            return false;
        }
        $ip = array_pop($ips);
        return $ip;
    }

    /**
     * @param string $header
     * @return string
     */
    protected function normalizeProxyHeader($header): string
    {
        $header = strtoupper($header);
        $header = str_replace('-', '_', $header);
        if (0 !== strpos($header, 'HTTP_')) {
            $header = 'HTTP_' . $header;
        }
        return $header;
    }

    /**
     * @return string|null
     */
    public function getCountry(): ?string
    {
        $output = null;
        $ip = $this->getIpAddress();
        $purpose = "country";
        if (filter_var($ip, FILTER_VALIDATE_IP) === FALSE) {
            $ip = $_SERVER["REMOTE_ADDR"];
            if (filter_var(@$_SERVER['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP))
                $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            if (filter_var(@$_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP))
                $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        $purpose = str_replace(array("name", "\n", "\t", " ", "-", "_"), null, strtolower(trim($purpose)));
        $support = array("country", "countrycode", "state", "region", "city", "location", "address");
        if (filter_var($ip, FILTER_VALIDATE_IP) && in_array($purpose, $support)) {
            $ipdat = @json_decode(file_get_contents("http://www.geoplugin.net/json.gp?ip=" . $ip));
            if (@strlen(trim($ipdat->geoplugin_countryCode)) == 2) {
                switch ($purpose) {
                    case "country":
                        $output = @$ipdat->geoplugin_countryName;
                        break;
                }
            }
        }
        return $output;
    }
}