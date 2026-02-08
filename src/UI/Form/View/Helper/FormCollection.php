<?php
namespace CodeCTRL\Apollo\UI\Form\View\Helper;


use Laminas\Form\ElementInterface;
use Laminas\Form\View\Helper\FormCollection as BaseFormCollection;
use CodeCTRL\Apollo\UI\Twig\Interfaces\TwigAwareInterface;
use CodeCTRL\Apollo\UI\Twig\Traits\TwigAwareTrait;
use CodeCTRL\Apollo\UI\Twig\Twig;

class FormCollection extends BaseFormCollection implements TwigAwareInterface
{
    use TwigAwareTrait;

    protected $onlyRenderTemplateWrapper =  '<span data-%s-template="%s"></span>';

    public function render(ElementInterface $element): string
    {
        $this->setWrapper('<div%4$s>%2$s%1$s%3$s</div>');
        return parent::render($element);
    }

    public function renderOnlyTemplate($twigFile, $id, $collection)
    {
        $this->setTemplateWrapper('%s');
        $this->setShouldWrap(false);

        $content = html_entity_decode(trim(parent::renderTemplate($collection)));

        /** @var Twig $twig */
        $twig = $this->twig;
        $template = $twig->render($twigFile, array('template'=>$content));

        return sprintf(
            $this->onlyRenderTemplateWrapper,
            $id,
            htmlentities($template)
        );
    }
}
