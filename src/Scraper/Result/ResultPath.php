<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

class ResultPath
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
}
