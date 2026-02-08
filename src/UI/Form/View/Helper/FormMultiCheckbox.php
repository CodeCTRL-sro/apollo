<?php

namespace CodeCTRL\Apollo\UI\Form\View\Helper;

class FormMultiCheckbox extends \Laminas\Form\View\Helper\FormMultiCheckbox
{
    /**
     * {@inheritdoc}
     */
    public function getInlineClosingBracket(): string
    {
        return '><span class="checkmark"></span>';
    }
}
