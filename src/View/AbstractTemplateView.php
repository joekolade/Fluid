<?php
declare(strict_types=1);
namespace TYPO3Fluid\Fluid\View;

/*
 * This file belongs to the package "TYPO3 Fluid".
 * See LICENSE.txt that was shipped with this package.
 */

use TYPO3Fluid\Fluid\Component\ComponentInterface;
use TYPO3Fluid\Fluid\Component\Error\ChildNotFoundException;
use TYPO3Fluid\Fluid\Core\Parser\PassthroughSourceException;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolver;
use TYPO3Fluid\Fluid\View\Exception\InvalidSectionException;
use TYPO3Fluid\Fluid\View\Exception\InvalidTemplateResourceException;

/**
 * Abstract Fluid Template View.
 *
 * Contains the fundamental methods which any Fluid based template view needs.
 */
abstract class AbstractTemplateView extends AbstractView
{

    /**
     * Constants defining possible rendering types
     */
    const RENDERING_TEMPLATE = 1;
    const RENDERING_PARTIAL = 2;
    const RENDERING_LAYOUT = 3;

    /**
     * The initial rendering context for this template view.
     * Due to the rendering stack, another rendering context might be active
     * at certain points while rendering the template.
     *
     * @var RenderingContextInterface
     */
    protected $baseRenderingContext;

    /**
     * Stack containing the current rendering type, the current rendering context, and the current parsed template
     * Do not manipulate directly, instead use the methods"getCurrent*()", "startRendering(...)" and "stopRendering()"
     *
     * @var array
     */
    protected $renderingStack = [];

    /**
     * Constructor
     *
     * @param null|RenderingContextInterface $context
     */
    public function __construct(RenderingContextInterface $context = null)
    {
        if ($context === null) {
            $context = new RenderingContext($this);
            $context->setControllerName('Default');
            $context->setControllerAction('Default');
        }
        $this->setRenderingContext($context);
    }

    /**
     * Initialize the RenderingContext. This method can be overridden in your
     * View implementation to manipulate the rendering context *before* it is
     * passed during rendering.
     */
    public function initializeRenderingContext(): void
    {
        $this->baseRenderingContext->getViewHelperVariableContainer()->setView($this);
        $this->baseRenderingContext->initialize();
    }

    /**
     * Gets the TemplatePaths instance from RenderingContext
     *
     * @return TemplatePaths
     */
    public function getTemplatePaths(): TemplatePaths
    {
        return $this->baseRenderingContext->getTemplatePaths();
    }

    /**
     * Gets the ViewHelperResolver instance from RenderingContext
     *
     * @return ViewHelperResolver
     */
    public function getViewHelperResolver(): ViewHelperResolver
    {
        return $this->baseRenderingContext->getViewHelperResolver();
    }

    /**
     * Gets the RenderingContext used by the View
     *
     * @return RenderingContextInterface
     */
    public function getRenderingContext(): RenderingContextInterface
    {
        return $this->baseRenderingContext;
    }

    /**
     * Injects a fresh rendering context
     *
     * @param RenderingContextInterface $renderingContext
     * @return void
     */
    public function setRenderingContext(RenderingContextInterface $renderingContext): void
    {
        $this->baseRenderingContext = $renderingContext;
        $this->initializeRenderingContext();
    }

    /**
     * Assign a value to the variable container.
     *
     * @param string $key The key of a view variable to set
     * @param mixed $value The value of the view variable
     * @return $this
     * @api
     */
    public function assign($key, $value): ViewInterface
    {
        $this->baseRenderingContext->getVariableProvider()->add($key, $value);
        return $this;
    }

    /**
     * Assigns multiple values to the JSON output.
     * However, only the key "value" is accepted.
     *
     * @param array $values Keys and values - only a value with key "value" is considered
     * @return $this
     * @api
     */
    public function assignMultiple(array $values): ViewInterface
    {
        $templateVariableContainer = $this->baseRenderingContext->getVariableProvider();
        foreach ($values as $key => $value) {
            $templateVariableContainer->add($key, $value);
        }
        return $this;
    }

    /**
     * Loads the template source and render the template.
     * If "layoutName" is set in a PostParseFacet callback, it will render the file with the given layout.
     *
     * @param string|null $actionName If set, this action's template will be rendered instead of the one defined in the context.
     * @return mixed Rendered Template
     * @api
     */
    public function render(?string $actionName = null)
    {
        $renderingContext = $this->getCurrentRenderingContext();
        $templateParser = $renderingContext->getTemplateParser();
        $templatePaths = $renderingContext->getTemplatePaths();
        if ($actionName) {
            $actionName = ucfirst($actionName);
            $renderingContext->setControllerAction($actionName);
        }
        try {
            $parsedTemplate = $this->getCurrentParsedTemplate();
            $parsedTemplate->getArguments()->setRenderingContext($renderingContext);
        } catch (PassthroughSourceException $error) {
            return $error->getSource();
        }

        try {
            $layoutNameNode = $parsedTemplate->getNamedChild('layoutName');
            $layoutName = $layoutNameNode->getArguments()->setRenderingContext($renderingContext)['name'];
        } catch (ChildNotFoundException $exception) {
            $layoutName = null;
        }

        if ($layoutName) {
            try {
                $parsedLayout = $templateParser->getOrParseAndStoreTemplate(
                    $templatePaths->getLayoutIdentifier($layoutName),
                    function($parent, TemplatePaths $paths) use ($layoutName): string {
                        return $paths->getLayoutSource($layoutName);
                    }
                );
                $parsedLayout->getArguments()->setRenderingContext($renderingContext);
            } catch (PassthroughSourceException $error) {
                return $error->getSource();
            }
            $this->startRendering(self::RENDERING_LAYOUT, $parsedTemplate, $this->baseRenderingContext);
            $output = $parsedLayout->evaluate($this->baseRenderingContext);
            $this->stopRendering();
        } else {
            $this->startRendering(self::RENDERING_TEMPLATE, $parsedTemplate, $this->baseRenderingContext);
            $output = $parsedTemplate->evaluate($this->baseRenderingContext);
            $this->stopRendering();
        }

        return $output;
    }

    /**
     * Renders a given section.
     *
     * @param string $sectionName Name of section to render
     * @param array $variables The variables to use
     * @param boolean $ignoreUnknown Ignore an unknown section and just return an empty string
     * @return mixed rendered template for the section
     * @throws InvalidSectionException
     */
    public function renderSection(string $sectionName, array $variables = [], bool $ignoreUnknown = false)
    {

        if ($this->getCurrentRenderingType() === self::RENDERING_LAYOUT) {
            // in case we render a layout right now, we will render a section inside a TEMPLATE.
            $renderingTypeOnNextLevel = self::RENDERING_TEMPLATE;
            $renderingContext = $this->getCurrentRenderingContext();
        } else {
            $renderingTypeOnNextLevel = $this->getCurrentRenderingType();
            $renderingContext = clone $this->getCurrentRenderingContext();
            $renderingContext->setVariableProvider($renderingContext->getVariableProvider()->getScopeCopy($variables));
        }

        try {
            $parsedTemplate = $this->getCurrentParsedTemplate();
        } catch (PassthroughSourceException $error) {
            return $error->getSource();
        } catch (InvalidTemplateResourceException $error) {
            if (!$ignoreUnknown) {
                return $renderingContext->getErrorHandler()->handleViewError($error);
            }
            return '';
        } catch (Exception $error) {
            return $renderingContext->getErrorHandler()->handleViewError($error);
        }

        try {
            $section = $parsedTemplate->getNamedChild($sectionName);
        } catch (ChildNotFoundException $exception) {
            if (!$ignoreUnknown) {
                return $renderingContext->getErrorHandler()->handleViewError($exception);
            }
            return '';
        }

        $this->startRendering($renderingTypeOnNextLevel, $parsedTemplate, $renderingContext);
        $output = $section->evaluate($renderingContext);
        $this->stopRendering();

        return $output;
    }

    /**
     * Renders a partial.
     *
     * @param string $partialName
     * @param string|null $sectionName
     * @param array $variables
     * @param boolean $ignoreUnknown Ignore an unknown section and just return an empty string
     * @return mixed
     */
    public function renderPartial(string $partialName, ?string $sectionName, array $variables, bool $ignoreUnknown = false)
    {
        $templatePaths = $this->baseRenderingContext->getTemplatePaths();
        $renderingContext = clone $this->getCurrentRenderingContext();
        try {
            $parsedPartial = $renderingContext->getTemplateParser()->getOrParseAndStoreTemplate(
                $templatePaths->getPartialIdentifier($partialName),
                function ($parent, TemplatePaths $paths) use ($partialName): string {
                    return $paths->getPartialSource($partialName);
                }
            );
            $parsedPartial->getArguments()->setRenderingContext($renderingContext);
        } catch (PassthroughSourceException $error) {
            return $error->getSource();
        } catch (InvalidTemplateResourceException $error) {
            if (!$ignoreUnknown) {
                return $renderingContext->getErrorHandler()->handleViewError($error);
            }
            return '';
        } catch (InvalidSectionException $error) {
            if (!$ignoreUnknown) {
                return $renderingContext->getErrorHandler()->handleViewError($error);
            }
            return '';
        } catch (Exception $error) {
            return $renderingContext->getErrorHandler()->handleViewError($error);
        }
        $this->startRendering(self::RENDERING_PARTIAL, $parsedPartial, $renderingContext);
        if ($sectionName !== null) {
            $output = $this->renderSection($sectionName, $variables, $ignoreUnknown);
        } else {
            $renderingContext->setVariableProvider($renderingContext->getVariableProvider()->getScopeCopy($variables));
            $output = $parsedPartial->evaluate($renderingContext);
        }
        $this->stopRendering();
        return $output;
    }

    /**
     * Start a new nested rendering. Pushes the given information onto the $renderingStack.
     *
     * @param integer $type one of the RENDERING_* constants
     * @param ComponentInterface $template
     * @param RenderingContextInterface $context
     * @return void
     */
    protected function startRendering(int $type, ComponentInterface $template, RenderingContextInterface $context): void
    {
        array_push($this->renderingStack, ['type' => $type, 'parsedTemplate' => $template, 'renderingContext' => $context]);
    }

    /**
     * Stops the current rendering. Removes one element from the $renderingStack. Make sure to always call this
     * method pair-wise with startRendering().
     *
     * @return void
     */
    protected function stopRendering(): void
    {
        array_pop($this->renderingStack);
    }

    /**
     * Get the current rendering type.
     *
     * @return integer one of RENDERING_* constants
     */
    protected function getCurrentRenderingType(): int
    {
        $currentRendering = end($this->renderingStack);
        return $currentRendering['type'] ? $currentRendering['type'] : self::RENDERING_TEMPLATE;
    }

    /**
     * Get the parsed template which is currently being rendered or compiled.
     *
     * @return ComponentInterface
     */
    protected function getCurrentParsedTemplate(): ComponentInterface
    {
        $currentRendering = end($this->renderingStack);
        $renderingContext = $this->getCurrentRenderingContext();
        $parsedTemplate = $currentRendering['parsedTemplate'] ?? null;
        if ($parsedTemplate) {
            return $parsedTemplate;
        }
        $templatePaths = $renderingContext->getTemplatePaths();
        $templateParser = $renderingContext->getTemplateParser();
        $controllerName = $renderingContext->getControllerName();
        $actionName = $renderingContext->getControllerAction();
        $parsedTemplate = $templateParser->getOrParseAndStoreTemplate(
            $templatePaths->getTemplateIdentifier($controllerName, $actionName),
            function($parent, TemplatePaths $paths) use ($controllerName, $actionName): string {
                return $paths->getTemplateSource($controllerName, $actionName);
            }
        );
        return $parsedTemplate;
    }

    /**
     * Get the rendering context which is currently used.
     *
     * @return RenderingContextInterface
     */
    protected function getCurrentRenderingContext(): RenderingContextInterface
    {
        $currentRendering = end($this->renderingStack);
        return $currentRendering['renderingContext'] ? $currentRendering['renderingContext'] : $this->baseRenderingContext;
    }
}
