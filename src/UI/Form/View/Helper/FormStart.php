<?php
namespace CodeCTRL\Apollo\UI\Form\View\Helper;

use Laminas\Form\FormInterface;
use Laminas\Form\View\Helper\Form;

class FormStart extends Form
{
    public function render(FormInterface $form): string
    {
        if (method_exists($form, 'prepare')) {
            $form->prepare();
        }
        return $this->openTag($form);
    }
}
