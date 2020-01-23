<?php
namespace wapmorgan\OpenApiGenerator\Generator\Result;

use OpenApi\Annotations\OpenApi;

class GeneratorResultSpecification
{
    /**
     * @var string Title of specification
     */
    public $title;

    /**
     * @var string ID of specification
     */
    public $id;

    /**
     * @var OpenApi
     */
    public $specification;
}
