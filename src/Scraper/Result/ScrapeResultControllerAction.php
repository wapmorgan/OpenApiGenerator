<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

class ScrapeResultControllerAction
{
    /**
     * @var ScrapeResultController Link to controller that owns action
     */
    public $controller;

    /**
     * @var string Id of action
     */
    public $actionId;

    /**
     * @var string Type of action; Available types:
     * - controllerMethod
     * - function
     * - externalClass
     */
    public $actionType;

    public const CONTROLLER_METHOD = 1,
        FUNCTION = 2,
        EXTERNAL_CLASS = 3;

    /**
     * @var string|null (when $actionType=controllerMethod) Name of controller method of this action
     */
    public $actionControllerMethod;
}