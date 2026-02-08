<?php
namespace CodeCTRL\Apollo\UI\Form\Translator;

use Laminas\Translator\TranslatorInterface;

trait TranslatorHelperTrait
{
    /**
     * @param $message
     * @param null $textDomain
     * @return string
     */
    public function __($message, $textDomain = null)
    {
        /** @var TranslatorInterface $translator */
        $translator = $this->getTranslator();
        return $translator->translate($message, $textDomain);
    }

    /**
     * @param $message
     * @return string
     */
    public function trans($message)
    {
        return $this->__($message, "default");
    }
}