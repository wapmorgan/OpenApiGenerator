<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

use wapmorgan\OpenApiGenerator\Initable;

class ResultTag extends Initable
{
    /**
     * @var string Name of tag
     */
    public $name;

    /**
     * @var string|null Description of tag
     */
    public $description;

    /**
     * @var string|null URL to external page
     */
    public $externalDocs;
}
