<?php

namespace CodeCTRL\Apollo\UI\Html\Builder;

use CodeCTRL\Apollo\UI\Html\Builder\Interfaces\DataTableBuilderInterface;
use CodeCTRL\Apollo\UI\Html\Builder\Traits\DataTableBuilderTrait;

class DataTableBuilder implements DataTableBuilderInterface
{
    use DataTableBuilderTrait;

    /**
     * @var array|mixed
     */
    private $extraOptions = array();

    /**
     * @param $extraOptions
     */
    public function __construct($extraOptions = array())
    {
        $this->extraOptions = $extraOptions;
    }

    /**
     * @param $name
     * @param $title
     * @param $sortable
     * @param $visible
     * @param $extraOptions
     * @return $this
     */
    public function addColumn($name, $title = '', $sortable = false, $visible = true, $extraOptions = array())
    {
        $this->addToColumn(array(
            'name' => $name,
            'title' => $title,
            'sortable' => $sortable,
            'visible' => $visible,
            'extraOptions' => $extraOptions,
        ));

        return $this;
    }

    /**
     * @param $title
     * @param $extraOptions
     * @return array
     */
    private function actionBtns($title, $extraOptions = array()){
        $actionBtns =  array(
            'type' => 'object',
            'value' => array(
                'title' => array(
                    'value' => $title,
                ),
                'data' => array(
                    'value' => 'operations',
                ),
                'className' => array(
                    'value' => 'text-center controls',
                ),
                'orderable' => array(
                    'type' => 'bool',
                    'value' => false,
                ),
                'width' => array(
                    'value' => '50px',
                ),
                'render' => array(
                    'type' => 'raw',
                    'value' => 'renderDataTableButtons',
                ),
            ),
        );
        if (!empty($extraOptions)) {
            $actionBtns['value'] = array_merge($actionBtns['value'], $extraOptions);
        }
        return $actionBtns;
    }

    /**
     * @return array[]
     */
    public function render()
    {
        $dataTableOptions = array(
            'serverSide' => array(
                'type' => 'bool',
                'value' => $this->isServerSide(),
            ),
            'pageLength' => array(
                'type' => 'raw',
                'value' => $this->getPageLength(),
            ),
            'lengthChange' => array(
                'type' => 'bool',
                'value' => $this->isLengthChange(),
            ),
            'ordering' => array(
                'type' => 'bool',
                'value' => $this->isOrdering(),
            ),
            'filter' => array(
                'type' => 'bool',
                'value' => $this->isFilter(),
            ),
            'language' => $this->getLanguage() == 'hu' ? array(
                'type' => 'object',
                'value' => array(
                    "url" => array(
                        'value' => $this->getLanguageUri()
                    )
                ),
            ) : array(),
            'ajax' => array(
                'type' => 'object',
                'value' => array(
                    'url' => array(
                        'value' => $this->getFetchUrl(),
                    ),
                    'type' => array(
                        'value' => 'GET',
                    ),
                ),
            ),
            'columns' => array(
                'type' => 'array',
                'value' => array(),
            ),
        );

        if (!empty($extraOptions)) {
            foreach ($extraOptions as $optionKey => $optionValue) {
                $dataTableOptions[$optionKey] = $optionValue;
            }
        }

        if(!empty($this->getColumns())){
            foreach ($this->getColumns() as $column){
                $columnDetails = array(
                    'type' => 'object',
                    'value' => array(
                        'title' => array(
                            'value' => $column['title'] ?? '',
                        ),
                        'data' => array(
                            'value' => $column['name'],
                        ),
                        'sortable' => array(
                            'type' => 'bool',
                            'value' => $column['sortable'] ?? false,
                        ),
                        'visible' => array(
                            'type' => 'bool',
                            'value' => $column['visible'] ?? true,
                        ),
                    ),
                );
                if (!empty($column['extraOptions'])) {
                    $columnDetails['value'] = array_merge($columnDetails['value'], $column['extraOptions']);
                }
                $dataTableOptions['columns']['value'][] = $columnDetails;
            }
        }

        if($this->isAddActionBtns()){
            $dataTableOptions['columns']['value'][] = $this->actionBtns($this->getActionBtnsTitle(), $this->getActionBtnsExtraOptions());
        }

        return $dataTableOptions;
    }
}