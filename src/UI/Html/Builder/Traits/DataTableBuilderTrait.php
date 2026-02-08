<?php

namespace CodeCTRL\Apollo\UI\Html\Builder\Traits;

trait DataTableBuilderTrait
{
    private bool $serverSide = true;
    private int $pageLength = 24;
    private bool $lengthChange = false;
    private bool $ordering = false;
    private bool $filter = true;
    private string $language = 'hu';
    private string $languageUri = '//cdn.datatables.net/plug-ins/9dcbecd42ad/i18n/Hungarian.json';
    private string|null $fetchUrl = null;
    private array $columns = array();
    private bool $addActionBtns = true;
    private string $actionBtnsTitle = 'Műveletek';
    private array $actionBtnsExtraOptions = array();

    /**
     * @return bool
     */
    public function isServerSide(): bool
    {
        return $this->serverSide;
    }

    /**
     * @param bool $serverSide
     * @return DataTableBuilderTrait
     */
    public function setServerSide(bool $serverSide): self
    {
        $this->serverSide = $serverSide;
        return $this;
    }

    /**
     * @return int
     */
    public function getPageLength(): int
    {
        return $this->pageLength;
    }

    /**
     * @param int $pageLength
     * @return DataTableBuilderTrait
     */
    public function setPageLength(int $pageLength): self
    {
        $this->pageLength = $pageLength;
        return $this;
    }

    /**
     * @return bool
     */
    public function isLengthChange(): bool
    {
        return $this->lengthChange;
    }

    /**
     * @param bool $lengthChange
     * @return DataTableBuilderTrait
     */
    public function setLengthChange(bool $lengthChange): self
    {
        $this->lengthChange = $lengthChange;
        return $this;
    }

    /**
     * @return bool
     */
    public function isOrdering(): bool
    {
        return $this->ordering;
    }

    /**
     * @param bool $ordering
     * @return DataTableBuilderTrait
     */
    public function setOrdering(bool $ordering): self
    {
        $this->ordering = $ordering;
        return $this;
    }

    /**
     * @return bool
     */
    public function isFilter(): bool
    {
        return $this->filter;
    }

    /**
     * @param bool $filter
     * @return DataTableBuilderTrait
     */
    public function setFilter(bool $filter): self
    {
        $this->filter = $filter;
        return $this;
    }

    /**
     * @return string
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * @param string $language
     * @return DataTableBuilderTrait
     */
    public function setLanguage(string $language): self
    {
        $this->language = $language;
        return $this;
    }

    /**
     * @return string
     */
    public function getLanguageUri(): string
    {
        return $this->languageUri;
    }

    /**
     * @param string $languageUri
     * @return DataTableBuilderTrait
     */
    public function setLanguageUri(string $languageUri): self
    {
        $this->languageUri = $languageUri;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getFetchUrl(): ?string
    {
        return $this->fetchUrl;
    }

    /**
     * @param string|null $fetchUrl
     * @return DataTableBuilderTrait
     */
    public function setFetchUrl(?string $fetchUrl): self
    {
        $this->fetchUrl = $fetchUrl;
        return $this;
    }

    /**
     * @return array
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @param array $columns
     * @return DataTableBuilderTrait
     */
    public function setColumns(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }
    
    /**
     * @param array $columnDetails
     * @return DataTableBuilderTrait
     */
    public function addToColumn(array $columnDetails): self
    {
        $this->columns[] = $columnDetails;
        return $this;
    }
    
    /**
     * @return bool
     */
    public function isAddActionBtns(): bool
    {
        return $this->addActionBtns;
    }

    /**
     * @param bool $addActionBtns
     * @return DataTableBuilderTrait
     */
    public function setAddActionBtns(bool $addActionBtns): self
    {
        $this->addActionBtns = $addActionBtns;
        return $this;
    }

    /**
     * @return string
     */
    public function getActionBtnsTitle(): string
    {
        return $this->actionBtnsTitle;
    }

    /**
     * @param string $actionBtnsTitle
     * @return DataTableBuilderTrait
     */
    public function setActionBtnsTitle(string $actionBtnsTitle): self
    {
        $this->actionBtnsTitle = $actionBtnsTitle;
        return $this;
    }

    /**
     * @return array
     */
    public function getActionBtnsExtraOptions(): array
    {
        return $this->actionBtnsExtraOptions;
    }

    /**
     * @param array $actionBtnsExtraOptions
     * @return DataTableBuilderTrait
     */
    public function setActionBtnsExtraOptions(array $actionBtnsExtraOptions): self
    {
        $this->actionBtnsExtraOptions = $actionBtnsExtraOptions;
        return $this;
    }
}
