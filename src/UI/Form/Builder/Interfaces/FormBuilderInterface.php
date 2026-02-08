<?php

namespace CodeCTRL\Apollo\UI\Form\Builder\Interfaces;

interface FormBuilderInterface
{
    public function isAjax();

    public function isAutoGenerateResponseDiv();

    public function isResetForm();

    public function getResultText();

    public function getActionUrl();

    public function getResultUrl();
}
