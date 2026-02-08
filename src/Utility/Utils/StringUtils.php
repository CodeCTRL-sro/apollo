<?php

namespace CodeCTRL\Apollo\Utility\Utils;

class StringUtils
{
    /**
     * @param int $length
     * @return string
     */
    public static function generateRandomString(int $length = 20): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        $randomStringFirstHalf = substr($randomString, 0, $length / 2);
        $randomStringMiddleHalf = time();
        $randomStringLastHalf = substr($randomString, $length / 2, $length / 2);
        return implode("", array($randomStringFirstHalf, $randomStringMiddleHalf, $randomStringLastHalf));
    }

    /**
     * @param $price
     * @param int $decimals
     * @return string
     */
    public static function priceFormatWD($price, int $decimals = 2): string
    {
        return number_format($price, $decimals, ",", ".");
    }

    /**
     * @param $price
     * @param int $decimals
     * @return string
     */
    public static function priceFormatWS($price, int $decimals = 2): string
    {
        return number_format($price, $decimals, ",", " ");
    }

    /**
     * @param $price
     * @param int $decimals
     * @return string
     */
    public static function priceFormatWC($price, int $decimals = 2): string
    {
        return number_format($price, $decimals, ".", ",");
    }

    /**
     * @param $string
     * @return string
     */
    public static function slugify($string): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', self::stripAccents($string)), '-'));
    }

    /**
     * @param $stripAccents
     * @return string
     */
    public static function stripAccents($stripAccents): string
    {
        $unwanted_array = array('Š' => 'S', 'š' => 's', 'Ž' => 'Z', 'ž' => 'z', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E',
            'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U',
            'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'B', 'ß' => 'Ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'a', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'o', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ý' => 'y', 'þ' => 'b', 'ÿ' => 'y', 'ű' => 'u', 'ő' => 'o', 'Ű' => 'U', 'Ő' => 'O', 'ü' => 'u');
        return strtr($stripAccents, $unwanted_array);
    }
}