<?php
namespace CodeCTRL\Apollo\UI\Form\Translator;

interface TranslatorLoaderInterface extends TranslatorAwareInterface
{
    /**
     * @param string|null $textDomain
     * @noinspection PhpUnused
     */
    public function autoLoadTranslator($textDomain = null);
}