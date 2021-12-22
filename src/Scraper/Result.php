<?php
namespace wapmorgan\OpenApiGenerator\Scraper;

use wapmorgan\OpenApiGenerator\InitableObject;

class Result extends InitableObject
{
    /**
     * @var Specification[]
     */
    public $specifications = [];
}
