<?php

namespace CodeCTRL\Apollo\UI\Html\Builder\Interfaces;

interface DataTableBuilderInterface
{
    public function isServerSide();

    public function getPageLength();

    public function isLengthChange();

    public function isOrdering();

    public function isFilter();

    public function getLanguage();

    public function getLanguageUri();

    public function getFetchUrl();

    public function getColumns();

    public function isAddActionBtns();

    public function getActionBtnsTitle();

    public function getActionBtnsExtraOptions();
}
