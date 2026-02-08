<?php

namespace CodeCTRL\Apollo\UI\Form;

use Laminas\I18n\Translator\Translator;
use Laminas\Mvc\I18n\Translator as MvcTranslator;
use Laminas\Validator\AbstractValidator;
use CodeCTRL\Apollo\UI\Form\Translator\TranslatorAwareInterface;
use CodeCTRL\Apollo\UI\Form\Translator\TranslatorAwareTrait;
use CodeCTRL\Apollo\UI\Form\Translator\TranslatorHelperInterface;
use CodeCTRL\Apollo\UI\Form\Translator\TranslatorHelperTrait;
use CodeCTRL\Apollo\UI\Form\Translator\TranslatorLoaderInterface;

class Form extends \Laminas\Form\Form implements TranslatorAwareInterface, TranslatorHelperInterface
{
    use TranslatorAwareTrait;
    use TranslatorHelperTrait;


    /**
     * @param $name
     * @param $options
     */
    public function __construct($name = null, $options = [])
    {
        parent::__construct($name, $options);
        if ($this instanceof TranslatorLoaderInterface) {
            $this->autoLoadTranslator();
        }
        if (!$this->translator instanceof MvcTranslator) {
            $this->setTranslator(new MvcTranslator(Translator::factory(array(
                'locale' => self::getLanguageFromUrl() ?? substr($_COOKIE["default_language"], 0, 2),
                'translation_file_patterns' => array(
                    array(
                        'type' => 'phparray',
                        'base_dir' => BASE_DIR . "/config/translations",
                        'pattern' => '%s.php',
                    ),
                ),
            ))));
        }

        AbstractValidator::setDefaultTranslator($this->translator);
        AbstractValidator::setDefaultTranslatorTextDomain(static::class);
    }

    /**
     * @return mixed|string
     */
    public function lang()
    {
        return self::getLanguageFromUrl() ?? (substr($_COOKIE["default_language"], 0, 2) ?? 'en');
    }

    /**
     * @param $array
     * @return array
     */
    public static function generateInputNameErrors($array)
    {
        $result = self::generateInputNameRec($array);
        $retArray = array();
        foreach ($result as $arrKey => $arrVal) {
            $exp = explode("]", $arrKey);
            $newKey = array_shift($exp);
            array_pop($exp);
            $newKey = $newKey . implode("]", $exp) . (count($exp) > 0 ? "]" : "");
            $retArray[$newKey][] = $arrVal;
        }
        return $retArray;
    }

    /**
     * @param $array
     * @param string $prefix
     * @return array
     */
    public static function generateInputNameRec($array, $prefix = '')
    {
        $result = array();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = $result + self::generateInputNameRec($value, $prefix . $key . '][');
            } else {
                $result[$prefix . $key] = $value;
            }
        }
        return $result;
    }

    /**
     * @return mixed|string|null
     */
    public static function getLanguageFromUrl()
    {
        $languages = array();
        $dirPath = BASE_DIR . '/config/translations';
        if (is_dir($dirPath)) {
            $files = scandir($dirPath);
            foreach ($files as $file) {
                if ($file != "." && $file != "..") {
                    $filePath = $dirPath . '/' . $file;
                    if (is_file($filePath)) {
                        $languages[] = str_replace('.php', '', $filePath);
                    }
                }
            }
            $request = $_GET["request"];
            $exp = explode("/", $request);
            if ($exp[0] == "admin") {
                if (in_array($exp[1], $languages)) {
                    return $exp[1];
                } else {
                    return null;
                }
            }
        }
        return $_GET["language"] ?? 'hu';
    }
}
