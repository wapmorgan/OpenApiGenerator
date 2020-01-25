<?php
namespace wapmorgan\OpenApiGenerator\Scraper;

use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\Scraper\Result\Result;

abstract class DefaultScrapper extends ErrorableObject
{
    /**
     * Should return list of controllers
     * @return Result
     */
    abstract public function scrape(): Result;
}
