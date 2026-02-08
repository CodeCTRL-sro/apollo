<?php

namespace CodeCTRL\Apollo\UI\Form\Builder;

use Doctrine\Laminas\Hydrator\DoctrineObject as DoctrineHydrator;
use Doctrine\ORM\EntityManagerInterface;
use Laminas\Form\Element\Button;
use Laminas\Form\Element\Text;
use Laminas\InputFilter\InputFilter;
use CodeCTRL\Apollo\UI\Form\Builder\Interfaces\FormBuilderInterface;
use CodeCTRL\Apollo\UI\Form\Builder\Traits\FormBuilderTrait;
use CodeCTRL\Apollo\UI\Form\Form;

class FormBuilder implements FormBuilderInterface
{
    use FormBuilderTrait;

    /**
     * @var Form
     */
    private Form $form;

    /**
     * @var array
     */
    private array $formFilters = array();

    /**
     * @var array
     */
    private array $formAttributes = array();

    /**
     * @param EntityManagerInterface $entityManager
     * @param $name
     * @param $formAttributes
     * @param $options
     */
    public function __construct(EntityManagerInterface $entityManager, $name, $formAttributes = array(), $options = array())
    {
        $this->form = new Form($name, $options);
        $this->form->setHydrator(new DoctrineHydrator($entityManager));
        $this->formAttributes = $formAttributes;
    }

    /**
     * @param $entity
     * @return object
     */
    public function createEntity($entity): object
    {
        return $this->form->getHydrator()->hydrate($this->form->getData(), $entity);
    }

    /**
     * @return void
     */
    private function buildAttributes()
    {
        $attributes = array(
            'data-ajax' => $this->isAjax(),
            'data-auto-generate-response-div' => $this->isAutoGenerateResponseDiv(),
            'data-reset-form' => $this->isResetForm(),
            'action' => $this->getActionUrl(),
        );
        if ($this->getResultText() != null) {
            $attributes['data-result-text'] = $this->getResultText();
        }
        if ($this->getResultUrl() != null) {
            $attributes['data-result-redirect'] = $this->getResultUrl();
        }
        if (!empty($this->formAttributes)) {
            $attributes = array_merge($attributes, $this->formAttributes);
        }
        $this->form->setAttributes($attributes);
    }

    /**
     * @return void
     */
    private function setInputFilters()
    {
        $filter = new InputFilter();
        if (!empty($this->formFilters)) {
            foreach ($this->formFilters as $formFilter) {
                $filter->add(
                    array(
                        'name' => $formFilter['name'],
                        'required' => $formFilter['required'],
                    )
                );
            }
        }
        $this->form->setInputFilter($filter);
    }

    /**
     * @param $name
     * @param $required
     * @param $label
     * @param $placeholder
     * @param $type
     * @param $class
     * @param $extraAttributes
     * @param $extraOptions
     * @return $this
     */
    public function addFieldset($name, $required = true, $label = null, $placeholder = null, $type = Text::class, $class = 'form-control', $extraAttributes = array(), $extraOptions = array())
    {
        $fieldsetOptions = array(
            'name' => $name,
            'type' => $type,
            'attributes' => array(
                'id' => $name,
                'required' => $required,
            ),
            'options' => array()
        );

        if ($class != null) {
            $fieldsetOptions['attributes']['class'] = $class;
        }
        if ($placeholder != null) {
            $fieldsetOptions['attributes']['placeholder'] = $placeholder;
        }
        if ($label != null) {
            $fieldsetOptions['options']['label'] = $label;
        }
        if (!empty($extraAttributes)) {
            $fieldsetOptions["attributes"] = array_merge($fieldsetOptions["attributes"], $extraAttributes);
        }
        if (!empty($extraOptions)) {
            $fieldsetOptions["options"] = array_merge($fieldsetOptions["options"], $extraOptions);
        }
        $this->formFilters[] = array(
            'name' => $name,
            'required' => $required,
        );
        $this->form->add($fieldsetOptions);
        return $this;
    }

    /**
     * @param $label
     * @param $align
     * @param $class
     * @param $extraAttributes
     * @param $extraOptions
     * @return $this
     */
    public function addSubmitButton($label, $align = 'center', $class = 'btn-primary', $extraAttributes = array(), $extraOptions = array())
    {
        $buttonOptions = array(
            'type' => Button::class,
            'name' => 'submit',
            'options' => array(
                'label' => $label,
                'label_options' => array(
                    'disable_html_escape' => true,
                ),
                'input-wrapper-class' => 'd-flex justify-content-' . $align,
            ),
            'attributes' => array(
                'type' => 'submit',
                'class' => 'btn ' . $class
            )
        );

        if (!empty($extraAttributes)) {
            $buttonOptions["attributes"] = array_merge($buttonOptions["attributes"], $extraAttributes);
        }
        if (!empty($extraOptions)) {
            $buttonOptions["options"] = array_merge($buttonOptions["options"], $extraOptions);
        }

        $this->form->add($buttonOptions);
        return $this;
    }

    /**
     * @return Form
     */
    public function render()
    {
        $this->buildAttributes();
        $this->setInputFilters();
        return $this->form;
    }
}