<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

class ResultTag
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
