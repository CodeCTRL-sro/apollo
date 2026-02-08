<?php

namespace CodeCTRL\Apollo\Security;

use voku\helper\AntiXSS;

class XSS
{
    /**
     * @var AntiXSS
     */
    private AntiXSS $antiXss;

    /**
     *
     */
    public function __construct()
    {
        $this->antiXss = new AntiXSS();
    }

    /**
     * @param $data
     * @return mixed|string|string[]
     */
    public function cleanData($data): mixed
    {
        if(is_array($data)){
            foreach($data as $key => $value){
                $data[$this->antiXss->xss_clean($key)] = $this->cleanData($value);
            }
        }else{
            $data = $this->antiXss->xss_clean($data);
        }
        return $data;
    }
}