<?php


namespace CodeCTRL\Apollo\UI\Twig;

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
        );
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
