<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

class ScrapeResultController
{
    /**
     * @var string|null Id of module that this controller belongs
     */
    public $moduleId;

    /**
     * @var string Id of controller
     */
    public $controllerId;

    /**
     * @var string Full name of controller class
     */
    public $class;

    /**
     * @var ScrapeResultControllerAction[] List of controller actions
     */
    public $actions;
}