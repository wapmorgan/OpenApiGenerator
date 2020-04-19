<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

use wapmorgan\OpenApiGenerator\InitableObject;
use wapmorgan\OpenApiGenerator\Scraper\DefaultPathResultWrapper;

class ResultPath extends InitableObject
{
    /**
     * List of possible path result types.
     * Direct - treating a specified in `@return` tag value as result of action
     * Another - treating another object, pointed in `$pathResult` property, as result of action
     */
    public const
        RESULT_DIRECT = 1,
        RESULT_REPLACED = 2;

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

    /**
     * @var int Type of result
     */
    public $pathResultType = self::RESULT_DIRECT;

    /**
     * @var null|string Pointer to another type that is the real result of action.
     * Makes sense only when result type is Another.
     * @todo Remove `$pathResultType` and use only `$pathResult` as null-by-default.
     */
    public $pathResult;
}
