<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use Exception;
use OpenApi\Annotations\Info;
use OpenApi\Annotations\OpenApi;
use ReflectionMethod;
use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\Generator\Result\GeneratorResult;
use wapmorgan\OpenApiGenerator\Generator\Result\GeneratorResultSpecification;
use wapmorgan\OpenApiGenerator\ReflectionsCollection;
use wapmorgan\OpenApiGenerator\Scraper\Result\Result;
use wapmorgan\OpenApiGenerator\Scraper\Result\ScrapeResultController;

class DefaultGenerator extends ErrorableObject
{
    /**
     * @var bool Should be different modules saved as separate specifications or not
     */
    public $splitByModule = true;

    /**
     * @var bool Should be module ID embedded in action (in path) or should be in server's url.
     * Works only when [[$splitByModule]] is enabled.
     */
    public $embedModuleInAction = true;

    /**
     * @var callable|null
     */
    protected $onStartCallback;
    /**
     * @var callable|null
     */
    protected $onEndCallback;
    /**
     * @var callable|null
     */
    protected $onSpecificationStartCallback;
    /**
     * @var callable|null
     */
    protected $onSpecificationEndCallback;
    /**
     * @var callable|null
     */
    protected $onControllerStartCallback;
    /**
     * @var callable|null
     */
    protected $onControllerEndCallback;
    /**
     * @var callable|null
     */
    protected $onControllerActionStartCallback;
    /**
     * @var callable|null
     */
    protected $onControllerActionEndCallback;
    /**
     * @var string Current module ID
     */
    protected $currentSpecificationId;

    /**
     * @param Result $scrapeResult
     * @return GeneratorResult
     * @throws \ReflectionException
     */
    public function generate(Result $scrapeResult): GeneratorResult
    {
        $this->call($this->onStartCallback, [$scrapeResult]);

        $result = new GeneratorResult();

        $by_modules = [];
        foreach ($scrapeResult->specifications as $specification) {
            $result->specifications[] = $this->generateSpecification($specification->version);
        }

        $this->call($this->onEndCallback, [$result]);

        return $result;
    }

    /**
     * @param callable|null $onStartCallback
     * @return DefaultGenerator
     */
    public function setOnStartCallback(?callable $onStartCallback): DefaultGenerator
    {
        $this->onStartCallback = $onStartCallback;
        return $this;
    }

    /**
     * @param callable|null $onEndCallback
     * @return DefaultGenerator
     */
    public function setOnEndCallback(?callable $onEndCallback): DefaultGenerator
    {
        $this->onEndCallback = $onEndCallback;
        return $this;
    }

    /**
     * @param callable|null $onSpecificationStartCallback
     * @return DefaultGenerator
     */
    public function setOnSpecificationStartCallback(?callable $onSpecificationStartCallback): DefaultGenerator
    {
        $this->onSpecificationStartCallback = $onSpecificationStartCallback;
        return $this;
    }

    /**
     * @param callable|null $onSpecificationEndCallback
     * @return DefaultGenerator
     */
    public function setOnSpecificationEndCallback(?callable $onSpecificationEndCallback): DefaultGenerator
    {
        $this->onSpecificationEndCallback = $onSpecificationEndCallback;
        return $this;
    }

    /**
     * @param callable|null $onControllerStartCallback
     * @return DefaultGenerator
     */
    public function setOnControllerStartCallback(?callable $onControllerStartCallback): DefaultGenerator
    {
        $this->onControllerStartCallback = $onControllerStartCallback;
        return $this;
    }

    /**
     * @param callable|null $onControllerEndCallback
     * @return DefaultGenerator
     */
    public function setOnControllerEndCallback(?callable $onControllerEndCallback): DefaultGenerator
    {
        $this->onControllerEndCallback = $onControllerEndCallback;
        return $this;
    }

    /**
     * @param callable|null $onControllerActionStartCallback
     * @return DefaultGenerator
     */
    public function setOnControllerActionStartCallback(?callable $onControllerActionStartCallback): DefaultGenerator
    {
        $this->onControllerActionStartCallback = $onControllerActionStartCallback;
        return $this;
    }

    /**
     * @param callable|null $onControllerActionEndCallback
     * @return DefaultGenerator
     */
    public function setOnControllerActionEndCallback(?callable $onControllerActionEndCallback): DefaultGenerator
    {
        $this->onControllerActionEndCallback = $onControllerActionEndCallback;
        return $this;
    }

    /**
     * @param callable|null $callback
     * @param array $params
     */
    protected function call(?callable $callback, array $params)
    {
        if ($callback === null) return;
        call_user_func_array($callback, $params);
    }

    /**
     * @param string|null $specificationId
     * @param ScrapeResultController[] $controllers
     * @return GeneratorResultSpecification
     * @throws \ReflectionException
     */
    protected function generateSpecification(?string $specificationId, array $controllers): GeneratorResultSpecification
    {
        $this->call($this->onSpecificationStartCallback, [$specificationId, $controllers]);

        $this->currentSpecificationId = $specificationId;

        $result = new GeneratorResultSpecification();
        $result->id = $specificationId;
        $result->title = trim(sprintf($this->specificationTitlePattern, $specificationId));
        $specification = new OpenApi([]);
        $specification->info = new Info([
            'title' => $result->title,
        ]);

        /** @var ScrapeResultController $controller */
        foreach ($controllers as $controller) {
            $this->call($this->onControllerStartCallback, [$controller]);

            foreach ($controller->actions as $controller_action) {
                $this->call($this->onControllerActionStartCallback, [$controller, $controller_action]);

                $action_reflection = ReflectionsCollection::getMethod($controller->class, $controller_action->actionControllerMethod);

                // URL с контролером. Опускаем default-контроллер в адресе
                $full_action_id = ($this->splitByModule && $this->embedModuleInAction && $specificationId !== null
                        ? $controller->moduleId
                        : null)
                    .($controller->controllerId !== 'default' ? '/' . $controller->controllerId : null)
                    . '/' . $controller_action->actionId;

                try {
                    $this->generateAnnotationForAction($action_reflection, $controller, $full_action_id);
                } catch (Exception $e) {
                    $this->notice('Error when working on '.$specificationId.':'.$controller->controllerId
                        .':'.$controller_action->actionId.': '.$e->getMessage().' ('.$e->getFile().':'.$e->getLine().')', static::NOTICE_ERROR);
                    $this->notice($e->getTraceAsString(), static::NOTICE_ERROR);
                }

                $this->call($this->onControllerActionEndCallback, [$controller, $controller_action]);
            }

            $this->call($this->onControllerEndCallback, [$controller]);
        }

        $this->call($this->onSpecificationEndCallback, [$controllers]);

        $result->specification = $specification;

        return $result;
    }

    protected function generateAnnotationForAction(
        ReflectionMethod $action_reflection,
        ScrapeResultController $controller,
        string $fullActionId)
    {
    }
}