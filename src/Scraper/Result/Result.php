<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

use wapmorgan\OpenApiGenerator\InitableObject;

class Result extends InitableObject
{
    /**
     * @var ResultSpecification[]
     */
    public $specifications = [];
}
