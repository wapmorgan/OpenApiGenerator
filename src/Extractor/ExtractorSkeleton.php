<?php
namespace wapmorgan\OpenApiGenerator\Extractor;

use wapmorgan\OpenApiGenerator\Generator\DefaultGenerator;

abstract class ExtractorSkeleton extends \wapmorgan\OpenApiGenerator\ErrorableObject
{
    /** @var DefaultGenerator */
    protected DefaultGenerator $generator;

    public function __construct(DefaultGenerator $generator)
    {
        $this->generator = $generator;
    }

    abstract public function extract(\ReflectionMethod $method, \ReflectionParameter $parameter, &$required = []);
}
