<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

class ScrapeResult
{
    /**
     * @var ScrapeResultController[] List of controllers
     */
    public $controllers;

    /**
     * @var int Number of actions
     */
    public $totalActions;
}