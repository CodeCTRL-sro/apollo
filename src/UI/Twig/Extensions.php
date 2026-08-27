<?php


namespace CodeCTRL\Apollo\UI\Twig;

use CodeCTRL\Apollo\Security\CSRF;
use CodeCTRL\Apollo\Utility\Helper\Helper;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class Extensions extends AbstractExtension
{
    /**
     * @var Helper
     */
    protected $helper;

    /**
     * ApolloContainer constructor.
     * @param Helper $helper
     */
    public function __construct(Helper $helper)
    {
        $this->helper = $helper;
    }

    /**
     * @return array An array of functions
     */
    public function getFunctions()
    {
        return array(
            new TwigFunction('getBasepath', array($this, 'basepath')),
            new TwigFunction('getFileTime', array($this, 'getFilemtime')),
            // Marked html-safe so the hidden inputs survive autoescaping.
            new TwigFunction('csrf_field', array($this, 'csrfField'), array('is_safe' => array('html'))),
            new TwigFunction('csrf_token', array($this, 'csrfToken')),
        );
    }

    /**
     * Hidden CSRF inputs for a form: {{ csrf_field('login') }}
     *
     * @param string $formId
     * @return string
     */
    public function csrfField($formId): string
    {
        return CSRF::field((string)$formId);
    }

    /**
     * The bare token, for XHR callers sending it as an X-CSRF-Token header.
     *
     * @param string $formId
     * @return string
     */
    public function csrfToken($formId): string
    {
        return CSRF::generateToken((string)$formId);
    }

    /**
     * @param $path
     * @return string
     */
    public function basepath($path,$rewritePath = false)
    {
        return !$rewritePath ? $this->helper->getRealUrl($path) : '/'.$path;
    }

    /**
     * file modify date
     *
     * @param string $path
     * @return int|false
     */
    public function getFilemtime($path)
    {
        return filemtime(implode(DIRECTORY_SEPARATOR, array($_SERVER["DOCUMENT_ROOT"], ltrim($path, '/\\'))));
    }
}
