<?php


namespace CodeCTRL\Apollo\UI\Form\View\Helper;


use Laminas\Form\ElementInterface;
use Laminas\View\Helper\AbstractHelper;

class FormPlainText extends AbstractHelper
{
    public function render(ElementInterface $element)
    {
        return $element->getValue();
    }

    public function __invoke(ElementInterface $element = null)
    {
        return $this->render($element);
    }
}
