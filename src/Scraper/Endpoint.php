<?php
namespace wapmorgan\OpenApiGenerator\Scraper;

use wapmorgan\OpenApiGenerator\InitableObject;

class Endpoint extends InitableObject
{
    /**
     * @var string Endpoint ID. Should be unique of all endpoints
     */
    public $id;

    /**
     * @var string[] List of security schemes applicable for endpoint
     */
    public $securitySchemes = [];

    /**
     * @var string[] List of tags for endpoint
     */
    public $tags = [];

    /**
     * @var string HTTP method for endpoint
     */
    public $httpMethod = 'GET';

    /**
     * @var callable Callback that this endpoint reacts
     */
    public $callback;

    /**
     * @var PathResultWrapper|null Endpoint result wrapper
     */
    public $resultWrapper;

    /**
     * @var null|string|object Pointer to another type that is the real result of endpoint.
     * Can be an object, which will be described as usual complex type (class).
     */
    public $result;
}
