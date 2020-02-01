<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

use wapmorgan\OpenApiGenerator\InitableObject;
use wapmorgan\OpenApiGenerator\Scraper\DefaultPathResultWrapper;

class ResultPath extends InitableObject
{
    /**
     * @var string Path ID. Should be unique of all paths
     */
    public $id;

    /**
     * @var string[] List of security schemes for path
     */
    public $securitySchemes = [];

    /**
     * @var string[] List of tags for path
     */
    public $tags = [];

    /**
     * @var string HTTP method for path
     */
    public $httpMethod = 'GET';

    /**
     * @var callable Callback that this path reacts
     */
    public $actionCallback;

    /**
     * @var DefaultPathResultWrapper|null Path result wrapper
     */
    public $pathResultWrapper;
}
