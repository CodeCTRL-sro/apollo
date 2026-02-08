<?php

namespace CodeCTRL\Apollo\Utility\Helper;

class CryptHelper
{
    private const CHIPER_ALGO = 'aes-256-ecb';

    /**
     * @param $data
     * @return string
     * @throws \Exception
     */
    public static function decrypt($data): string
    {
        $decrypt = openssl_decrypt(base64_decode($data), $_ENV["CRYPT_ALGO"] ?? self::CHIPER_ALGO, $_ENV["CRYPT_SECRET"], OPENSSL_RAW_DATA);
        if ($decrypt !== false) {
            return $decrypt;
        }
        throw new \Exception(openssl_error_string());
    }

    /**
     * @param $data
     * @return string
     * @throws \Exception
     */
    public static function encrypt($data): string
    {
        $encrypt = openssl_encrypt($data, $_ENV["CRYPT_ALGO"] ?? self::CHIPER_ALGO, $_ENV["CRYPT_SECRET"], OPENSSL_RAW_DATA);
        if ($encrypt !== false) {
            return base64_encode($encrypt);
        }
        throw new \Exception(openssl_error_string());
    }
}