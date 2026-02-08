<?php

namespace CodeCTRL\Apollo\Utility\Helper;

class ProfanityHelper
{
    protected $profanities = [];

    public function __construct()
    {
        if (!isset($_ENV['PROFANITIES_PATH'])) {
            throw new \Exception("Profanities path is not set");
        }

        $this->profanities = $this->loadProfanitiesFromFile($_ENV['PROFANITIES_PATH']);
    }

    private function loadProfanitiesFromFile($config)
    {
        return include($config);
    }

    /**
     * @param $string
     * @return string
     */
    private function stripHTMLTags($string): string
    {
        $string = preg_replace('/<!--[\s\S]*?-->/', '', $string);
        $string = preg_replace('/<\?.*?\?>/', '', $string);
        $string = preg_replace('/<![^>]*>/', '', $string);
        $string = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $string);
        $string = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $string);
        $string = strip_tags($string);
        $string = html_entity_decode($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $string = preg_replace('/\s+/', ' ', $string);

        return trim($string);
    }

    /**
     * @param $text
     * @return array
     */
    public function getProfanity($text): array
    {
        $text = $this->stripHTMLTags($text);
        $text = strtolower($text);

        $foundProfanity = [];

        foreach ($this->profanities as $word) {
            $word = strtolower($word);

            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $text)) {
                $foundProfanity[] = $word;
            }
        }

        return array(
            'containsProfanity' => !empty($foundProfanity),
            'matches' => $foundProfanity
        );
    }
}