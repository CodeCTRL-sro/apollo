<?php

namespace CodeCTRL\Apollo\Utility\Logger;

class Browser
{
    /** @var mixed $userAgent */
    private $userAgent;

    /** @var string $name */
    private $name;

    /** @var string $version */
    private $version;

    /** @var string $platform */
    private $platform;

    /** @var string $pattern */
    private $pattern;

    public function __construct()
    {
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'];
        $bname = 'Unknown';
        $platform = 'Unknown';
        $version = "";

        if (preg_match('/linux/i', $this->userAgent)) {
            $platform = 'linux';
        } elseif (preg_match('/macintosh|mac os x/i', $this->userAgent)) {
            $platform = 'mac';
        } elseif (preg_match('/windows|win32/i', $this->userAgent)) {
            $platform = 'windows';
        }
        $this->platform = $platform;

        if (preg_match('/MSIE/i', $this->userAgent) && !preg_match('/Opera/i', $this->userAgent)) {
            $bname = 'Internet Explorer';
            $ub = "MSIE";
        } elseif (preg_match('/Firefox/i', $this->userAgent)) {
            $bname = 'Mozilla Firefox';
            $ub = "Firefox";
        } elseif (preg_match('/Chrome/i', $this->userAgent)) {
            $bname = 'Google Chrome';
            $ub = "Chrome";
        } elseif (preg_match('/Safari/i', $this->userAgent)) {
            $bname = 'Apple Safari';
            $ub = "Safari";
        } elseif (preg_match('/Opera/i', $this->userAgent)) {
            $bname = 'Opera';
            $ub = "Opera";
        } elseif (preg_match('/Netscape/i', $this->userAgent)) {
            $bname = 'Netscape';
            $ub = "Netscape";
        }
        $this->name = $ub;

        $known = array('Version', $ub, 'other');
        $pattern = '#(?<browser>' . join('|', $known) .
            ')[/ ]+(?<version>[0-9.|a-zA-Z.]*)#';
        if (!preg_match_all($pattern, $this->userAgent, $matches)) {
        }
        $this->pattern = $pattern;

        $i = count($matches['browser']);
        if ($i != 1) {
            if (strripos($this->userAgent, "Version") < strripos($this->userAgent, $ub)) {
                $version = $matches['version'][0];
            } else {
                $version = $matches['version'][1];
            }
        } else {
            $version = $matches['version'][0];
        }

        if ($version == null || $version == "") {
            $version = "?";
        }
        $this->version = $version;
    }

    /**
     * @return mixed
     */
    public function getUserAgent()
    {
        return $this->userAgent;
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @return string
     */
    public function getPlatform()
    {
        return $this->platform;
    }

    /**
     * @return string
     */
    public function getPattern()
    {
        return $this->pattern;
    }
}