<?php
namespace CodeCTRL\Apollo\UI\Twig;

use CodeCTRL\Apollo\Utility\Logger\Interfaces\LoggerHelperInterface;
use CodeCTRL\Apollo\Utility\Logger\Traits\LoggerHelperTrait;
use Twig\Environment;
use Twig\Error\Error;

class Twig extends Environment implements LoggerHelperInterface
{
    use LoggerHelperTrait;

    /**
     * @param $name
     * @param array $context
     * @return string
     */
    public function render($name, array $context = array()) :string
    {
        try {
            $page = parent::render($name, $context);
        } catch (Error $e) {
            $this->error('Twig_Error', array(
                'template' => is_string($name) ? $name : get_debug_type($name),
                'message' => $e->getMessage(),
                'line' => $e->getTemplateLine(),
            ));

            // Swallowing the error and returning '' turns a broken template into a
            // blank page with nothing on screen to explain it. In debug mode the
            // exception is rethrown so the error page can show what actually failed;
            // production keeps the previous forgiving behaviour.
            if ($this->log_debug) {
                throw $e;
            }

            $page = '';
        }
        return $page;
    }
}
