<?php

namespace CodeCTRL\Apollo\UI\Form\Translator;

use Exception;
use Laminas\I18n\Translator\Translator;

trait TranslatorLoaderTrait
{
    protected ?Translator $translator = null;

    /**
     * @param string|null $textDomain
     * @noinspection PhpUnused
     */
    public function autoLoadTranslator($textDomain = null)
    {
        if (!$this->translator instanceof Translator) {
            $this->translator = new Translator();
        }
        try {
            $this->translator->addTranslationFilePattern(
                'PhpArray',
                BASE_DIR . '/config/translations',
                '%s.php',
                static::class
            );
        } catch (Exception $e) {
        }
    }
}