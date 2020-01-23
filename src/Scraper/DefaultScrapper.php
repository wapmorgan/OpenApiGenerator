<?php
namespace wapmorgan\OpenApiGenerator\Scraper;

use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\Scraper\Result\ScrapeResult;

abstract class DefaultScrapper extends ErrorableObject
{
    /**
     * Should return list of controllers
     * @return ScrapeResult
     */
    abstract public function scrape(): ScrapeResult;
}
