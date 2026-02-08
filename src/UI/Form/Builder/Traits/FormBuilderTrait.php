<?php

namespace CodeCTRL\Apollo\UI\Form\Builder\Traits;

trait FormBuilderTrait
{
    protected bool $ajax = true;
    protected bool $autoGenerateResponseDiv = true;
    protected bool $resetForm = true;
    protected string|null $resultText = null;
    protected string|null $actionUrl = null;
    protected string|null $resultUrl = null;

    /**
     * @return bool
     */
    public function isAjax(): bool
    {
        return $this->ajax;
    }

    public function setAjax(bool $ajax): static
    {
        $this->ajax = $ajax;
        return $this;
    }

    /**
     * @return bool
     */
    public function isAutoGenerateResponseDiv(): bool
    {
        return $this->autoGenerateResponseDiv;
    }

    /**
     * @param bool $autoGenerateResponseDiv
     * @return $this
     */
    public function setAutoGenerateResponseDiv(bool $autoGenerateResponseDiv): static
    {
        $this->autoGenerateResponseDiv = $autoGenerateResponseDiv;
        return $this;
    }

    /**
     * @return bool
     */
    public function isResetForm(): bool
    {
        return $this->resetForm;
    }

    /**
     * @param bool $resetForm
     * @return $this
     */
    public function setResetForm(bool $resetForm): static
    {
        $this->resetForm = $resetForm;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getResultText(): ?string
    {
        return $this->resultText;
    }

    /**
     * @param string|null $resultText
     * @return $this
     */
    public function setResultText(?string $resultText): static
    {
        $this->resultText = $resultText;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getActionUrl(): ?string
    {
        return $this->actionUrl;
    }

    /**
     * @param string|null $actionUrl
     * @return $this
     */
    public function setActionUrl(?string $actionUrl): static
    {
        $this->actionUrl = $actionUrl;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getResultUrl(): ?string
    {
        return $this->resultUrl;
    }

    /**
     * @param string|null $resultUrl
     * @return $this
     */
    public function setResultUrl(?string $resultUrl): static
    {
        $this->resultUrl = $resultUrl;
        return $this;
    }
}
