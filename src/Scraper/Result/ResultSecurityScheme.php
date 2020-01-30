<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

use wapmorgan\OpenApiGenerator\InitableObject;

class ResultSecurityScheme extends InitableObject
{
    /**
     * @var string ID of security scheme
     */
    public $id;

    /**
     * @var string Type of security scheme
     */
    public $type;

    /**
     * @var string
     */
    public $in;

    /**
     * @var string Name for security scheme parameter
     */
    public $name;

    /**
     * @var string Description of security scheme
     */
    public $description;
}
