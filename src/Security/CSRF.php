<?php

namespace CodeCTRL\Apollo\Security;

use CodeCTRL\Apollo\UI\Form\Form;

class CSRF
{
    /**
     * @param $formId
     * @param $withHtml
     * @return string
     * @throws \Random\RandomException
     */
    public static function generateToken($formId, $withHtml = false, $regenerate = false): string
    {
        $token = $_SESSION['_csrf__'.$formId];
        if($regenerate){
            $token = bin2hex(random_bytes(32));
        }
        $_SESSION['_csrf__'.$formId] = $token;

        return $withHtml ? "<input name='token' value='$token' type= 'hidden'>" : $token;
    }

    /**
     * @param $formId
     * @param $token
     * @return bool
     */
    public static function verifyToken($formId, $token): bool
    {
        return isset($_SESSION['_csrf__'.$formId]) && $_SESSION['_csrf__'.$formId] === $token;
    }

    /**
     * @param Form $form
     * @return array|array[]
     */
    public static function formValidate(Form $form): array
    {
        $formId = $form->getAttribute('name');
        $formData = $form->getData();
        $token = $formData['_csrf'] ?? '';
        if(!self::verifyToken($formId, $token)){
            return array('submit' => array('Invalid CSRF token, please refresh the page_'.$token));
        }
        return array();
    }
}