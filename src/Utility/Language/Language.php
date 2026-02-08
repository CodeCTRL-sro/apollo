<?php

namespace CodeCTRL\Apollo\Utility\Language;


use Doctrine\ORM\EntityManagerInterface;
use Exception;
use CodeCTRL\Apollo\Core\ApolloContainer;
use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Security\Auth\Auth;
use CodeCTRL\Apollo\Utility\Helper\Helper;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Twig\Environment;

class Language extends ApolloContainer
{
    protected $languages;
    protected $app_languages = array();
    protected $default_language;
    protected $lang;
    protected $translate = array();
    protected $app_translate = array();
    protected static string $NAME;
    protected static $URLS = array();
    protected $request;
    protected string|null $app_language_version = null;

    public function __construct(ServerRequestInterface $request, Config $config, Environment $twig, Helper $helper, Auth $auth, EntityManagerInterface $entityManager = null, LoggerInterface $logger = null)
    {
        $this->request = $request;
        $this->languages = array();
        foreach (array_diff(scandir($config->get(array('route', 'translator', 'path'), '')), array('.', '..')) as $lang) {
            if (strpos($lang, '.php') !== false) {
                $this->languages[] = str_replace(".php", "", $lang);
            }
        }

        if(!empty($config->get(array('route', 'translator', 'appPath'), ''))) {
            $this->app_languages = array();
            foreach (array_diff(scandir($config->get(array('route', 'translator', 'appPath'), '')), array('.', '..')) as $lang) {
                if (strpos($lang, '.php') !== false) {
                    $langCode = str_replace(".php", "", $lang);
                    $this->app_languages[] = $langCode;
                    $this->app_translate[$langCode] = include($config->get(array('route', 'translator', 'appPath'), null) . '/' . $langCode . '.php');
                }
            }
        }

        $this->app_language_version = $config->get(array('route', 'translator', 'version'), null);

        $this->default_language = $config->get(array('route', 'translator', 'default'), 'hu');
        $this->lang = self::parseLang($config, $helper->getBasepath());
        foreach ($this->languages as $lang) {
            $this->translate[$lang] = include($config->get(array('route', 'translator', 'path'), null) . '/' . $lang . '.php');
        }
        $twig->addGlobal('__lang', $this->lang);
        $twig->addGlobal('__lang_urls', $this->getUrls());
        $twig->addGlobal('__languages', $this->languages);
        $twig->addGlobal('__global_translations', $this->translate[$this->lang]);

        setcookie('default_language', substr($this->lang, 0,2), strtotime('+365 days'), '/', secure: true, httponly: true);

		parent::__construct($config, $twig, $helper, $auth, $entityManager, $logger);
    }

    /**
     * @param Config $config
     * @return string
     */
    public static function parseLang(Config $config, $basePath = null)
    {
        $languages = array();
        $dir = $config->get(array('route', 'translator', 'path'), null);
        if(!empty($dir)){
            foreach (array_diff(scandir($config->get(array('route', 'translator', 'path'), '')), array('.', '..')) as $lang) {
                $languages[] = str_replace(".php", "", $lang);
            }

            if (isset($_SERVER["HTTP_CONTENT_LANGUAGE"])) {
                if (!empty($_SERVER["HTTP_CONTENT_LANGUAGE"])) {
                    if (in_array($_SERVER["HTTP_CONTENT_LANGUAGE"], $languages)) {
                        return $_SERVER["HTTP_CONTENT_LANGUAGE"];
                    }
                }
            }

            $params = $_GET;

            if ($basePath != null) {
                if ($basePath != '/') {
                    if (substr_count($params["request"], '/') >= 1) {
                        $lang = explode('/', $params["request"])[1];
                        $params["language"] = $lang;
                    }
                }
            }

            if (isset($params["language"])) {
                if (in_array($params["request"], $languages)) {
                    return $params["request"];
                }
                if (in_array($params["language"], $languages)) {
                    return $params["language"];
                }
            }
            if (array_key_exists('request', $params)) {
                $tmp = explode('/', $params['request']);
                $lng = array_shift($tmp);
                if (strpos($params["request"], 'api/') === false) {
                    if (isset($_COOKIE["default_language"])) {
                        return substr($_COOKIE["default_language"], 0,2);
                    }
                }
                $headerLang = (isset($_SERVER["HTTP_CONTENT_LANGUAGE"]) ? $_SERVER["HTTP_CONTENT_LANGUAGE"] : $config->get(array('route', 'translator', 'default'), 'hu'));
                return in_array($lng, $languages) ? $lng : (!empty($headerLang) ? (in_array($headerLang, $languages) ? $headerLang : $config->get(array('route', 'translator', 'default'), 'hu')) : $config->get(array('route', 'translator', 'default'), 'hu'));
            } else {
                return $config->get(array('route', 'translator', 'default'), 'hu');
            }
        }
        return 'hu';
    }

    /**
     * @return string
     */
    public static function getURL()
    {
        return static::$URL;
    }

    /**
     * @return string|null
     */
    public function getAppLanguageVersion(): string|null
    {
        return $this->app_language_version;
    }


    /**
     * @return string|null
     */
    public function getLang(): string|null
    {
        return $this->lang;
    }

    /**
     * @param $key
     * @param string $lang
     * @return mixed
     */
    public function trans($key, $lang = '')
    {
        if (!$lang) {
            $lang = $this->lang;
        }
        $text = '';
        if (isset($this->translate[$lang][$key])) {
            $text = $this->translate[$lang][$key];
        } else {
            if (isset($this->translate[$this->default_language][$key])) {
                $text = $this->translate[$this->default_language][$key];
            } else {
                $text = '--{' . $key . '}--';
            }
        }
        return $text;
    }

    public function search($term, $lang = '')
    {
        if (!$lang) {
            $lang = $this->lang;
        }
        foreach ($this->translate[$lang] as $txt) {
            if (is_string($txt)) {
                if (mb_stripos($txt, $term) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array
     */
    public function getUrls()
    {
        return static::$URLS;
    }

    /**
     * @param $class
     * @param ServerRequestInterface $request
     * @return self
     */
    protected function loadMultiModule($class, ServerRequestInterface $request = null)
    {
        try {
            $reflector = new ReflectionClass($class);
        } catch (Exception $exception) {
            return null;
        }
        if (in_array(LanguageModulesInterface::class, $reflector->getInterfaceNames())) {
            if (!$request) {
                $request = $this->request;
            }
            $config = new Config(array('route' => $this->config->toArray()));
            return new $class($config, $this->twig, $this->entityManager, $this->helper, $request, $this->logger, true);
        }
        return null;
    }

    /**
     * @return array|string|null
     */
    public function getLanguages()
    {
        return $this->languages;
    }

    /**
     * @return array|null
     */
    public function getTranslations()
    {
        return $this->translate[$this->lang];
    }

    /**
     * @param bool    $isAppTranslations Optional. Generate normal version of translations, or one for application. Defaults to `false`.
     * @return string Returns the path of generated file, if error found returns the error message.
     */
    public function exportLanguagesToExcel(bool $isAppTranslations = false): string
    {
        $allRequiredClassesFound = true;
        $requiredClassList = array('\PhpOffice\PhpSpreadsheet\Spreadsheet', '\PhpOffice\PhpSpreadsheet\Writer\Xlsx', '\PhpOffice\PhpSpreadsheet\Writer\Exception');
        foreach ($requiredClassList as $classList) {
            if (!class_exists($classList)) {
                $allRequiredClassesFound = false;
            }
        }
        if ($allRequiredClassesFound) {
            $exportData = $this->convertTranslationsDataToExcel($isAppTranslations);
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $spreadsheet->getActiveSheet()->fromArray($exportData);
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            try {
                $fileLocation = BASE_DIR . '/translations.xlsx';
                if ($isAppTranslations){
                    $fileLocation = BASE_DIR . '/app_translations.xlsx';
                }
                $writer->save($fileLocation);
                return 'Your file location is: ' . $fileLocation;
            } catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $e) {
                return 'Something went wrong: ' . $e->getMessage();
            }
        } else {
            return 'Some required class missing!';
        }
    }

    /**
     * @return string Returns status of the generation.
     */
    public function exportAppLanguagesToJSON(): string
    {
        foreach ($this->app_translate as $key => $lang){
            $fileLocation = BASE_DIR . '/'.$key.'.json';
            try {
                file_put_contents($fileLocation, json_encode($lang));
            }catch (\Exception $e){
                return 'Something went wrong: ' . $e->getMessage();
            }
        }
        return 'Files successfully created!';
    }

    /**
     * @return array
     */
    private function convertTranslationsDataToExcel(bool $isAppTranslations): array
    {
        $result = array();
        $translationsArray = $this->translate;
        if ($isAppTranslations){
            $translationsArray = $this->app_translate;
        }
        $keys = array_keys($translationsArray[array_key_first($translationsArray)]);
        $result[] = array_merge(array("Rendszer kulcs"), array_keys($translationsArray));
        foreach ($keys as $key) {
            $row = array($key);
            foreach ($translationsArray as $language => $translations) {
                $row[] = $translations[$key] ?? "";
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * @param bool    $isAppTranslations Optional. Import normal version of translations, or one for application. Defaults to `false`.
     * @param string  $fileLocation Optional. Location of import file. Defaults to `DOCUMENT_ROOT/translations.xlsx` when isAppTranslations is true defaults to `DOCUMENT_ROOT/app_translations.xlsx`.
     * @return string
     */
    public function importLanguagesFromExcel(bool $isAppTranslations = false, $fileLocation = null): string
    {
        if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            if ($fileLocation == null) {
                $fileLocation = BASE_DIR . '/translations.xlsx';
                if ($isAppTranslations){
                    $fileLocation = BASE_DIR . '/app_translations.xlsx';
                }
            }
            if (file_exists($fileLocation)) {
                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileLocation);
                    $sheet = $spreadsheet->getSheet($spreadsheet->getFirstSheetIndex());
                    $data = $this->eliminateNullValues($sheet->toArray());
                    $result = $this->convertExcelDataToTranslationsFile($data, $isAppTranslations);
                    if ($result) {
                        return 'Files successfully created!';
                    } else {
                        return 'Something went wrong while importing the files, please check excel structure and data!';
                    }
                } catch (Exception $e) {
                    return 'Something went wrong: ' . $e->getMessage();
                }
            } else {
                return 'The xlsx file doesn\'t exist!';
            }
        } else {
            return 'Some required class missing!';
        }
    }

    /**
     * @param $data
     * @return mixed
     */
    private function eliminateNullValues($data): mixed
    {
        foreach ($data as $key => &$row) {
            $row = array_filter($row, function ($cell) {
                return !is_null($cell);
            });
            if (count($row) == 0) {
                unset($data[$key]);
            }
        }
        unset ($row);
        return $data;
    }

    /**
     * @param $data
     * @return bool
     */
    private function convertExcelDataToTranslationsFile($data, bool $isAppTranslations = false): bool
    {
        $convertedDataWithoutHeaders = array_slice($data, 1);
        $convertedData = array();
        foreach ($convertedDataWithoutHeaders as $row) {
            $key = $row[0];
            for ($i = 1; $i < count($row); $i++) {
                $language = $data[0][$i];
                $value = $row[$i];
                if (!isset($convertedData[$language])) {
                    $convertedData[$language] = array();
                }
                $convertedData[$language][$key] = $value;
            }
        }
        return $this->saveConvertedExcelDataToTranslationFiles($convertedData, $isAppTranslations);
    }

    /**
     * @param $convertedData
     * @return bool
     */
    private function saveConvertedExcelDataToTranslationFiles($convertedData, bool $isAppTranslations = false): bool
    {
        $filesCreated = true;
        $folderLocation = BASE_DIR. '/config/translations';
        if ($isAppTranslations){
            $folderLocation = BASE_DIR . '/config/translations/app';
        }
        foreach ($convertedData as $language => $data) {
            if (file_put_contents($folderLocation . '/' . $language . '.php', "<?php \n\n return " . var_export($data, true) . ";") == false) {
                $filesCreated = false;
            }
        }
        return $filesCreated;
    }
}