<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use Exception;
use OpenApi\Annotations\Components;
use OpenApi\Annotations\Info;
use OpenApi\Annotations\OpenApi;
use OpenApi\Annotations\SecurityScheme;
use OpenApi\Annotations\Server;
use OpenApi\Annotations\Tag;
use ReflectionMethod;
use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\Generator\Result\GeneratorResult;
use wapmorgan\OpenApiGenerator\Generator\Result\GeneratorResultSpecification;
use wapmorgan\OpenApiGenerator\ReflectionsCollection;
use wapmorgan\OpenApiGenerator\Scraper\Result\Result;
use wapmorgan\OpenApiGenerator\Scraper\Result\ResultPath;
use wapmorgan\OpenApiGenerator\Scraper\Result\ResultSpecification;
use wapmorgan\OpenApiGenerator\Scraper\Result\ScrapeResultController;

class DefaultGenerator extends ErrorableObject
{
    /**
     * @var bool Should be different modules saved as separate specifications or not
     */
    public $splitByModule = true;

    /**
     * @var bool Should be module ID embedded in action (in path) or should be in server's url.
     * Works only when [[$splitByModule]] is enabled.
     */
    public $embedModuleInAction = true;

    /**
     * @var callable|null
     */
    protected $onStartCallback;
    /**
     * @var callable|null
     */
    protected $onEndCallback;
    /**
     * @var callable|null
     */
    protected $onSpecificationStartCallback;
    /**
     * @var callable|null
     */
    protected $onSpecificationEndCallback;
    /**
     * @var callable|null
     */
    protected $onControllerStartCallback;
    /**
     * @var callable|null
     */
    protected $onControllerEndCallback;
    /**
     * @var callable|null
     */
    protected $onPathStartCallback;
    /**
     * @var callable|null
     */
    protected $onPathEndCallback;
    /**
     * @var string Current specification ID
     */
    protected $currentSpecificationId;

    /**
     * @var OpenApi
     */
    protected $currentOpenApi;

    /**
     * @param Result $scrapeResult
     * @return GeneratorResult
     * @throws \ReflectionException
     */
    public function generate(Result $scrapeResult): GeneratorResult
    {
        $this->call($this->onStartCallback, [$scrapeResult]);

        $result = new GeneratorResult();

        $by_modules = [];
        foreach ($scrapeResult->specifications as $specification) {
            $result->specifications[] = $this->generateSpecification($specification);
        }

        $this->call($this->onEndCallback, [$result]);

        return $result;
    }

    /**
     * @param callable|null $onStartCallback
     * @return DefaultGenerator
     */
    public function setOnStartCallback(?callable $onStartCallback): DefaultGenerator
    {
        $this->onStartCallback = $onStartCallback;
        return $this;
    }

    /**
     * @param callable|null $onEndCallback
     * @return DefaultGenerator
     */
    public function setOnEndCallback(?callable $onEndCallback): DefaultGenerator
    {
        $this->onEndCallback = $onEndCallback;
        return $this;
    }

    /**
     * @param callable|null $onSpecificationStartCallback
     * @return DefaultGenerator
     */
    public function setOnSpecificationStartCallback(?callable $onSpecificationStartCallback): DefaultGenerator
    {
        $this->onSpecificationStartCallback = $onSpecificationStartCallback;
        return $this;
    }

    /**
     * @param callable|null $onSpecificationEndCallback
     * @return DefaultGenerator
     */
    public function setOnSpecificationEndCallback(?callable $onSpecificationEndCallback): DefaultGenerator
    {
        $this->onSpecificationEndCallback = $onSpecificationEndCallback;
        return $this;
    }

    /**
     * @param callable|null $onControllerStartCallback
     * @return DefaultGenerator
     */
    public function setOnControllerStartCallback(?callable $onControllerStartCallback): DefaultGenerator
    {
        $this->onControllerStartCallback = $onControllerStartCallback;
        return $this;
    }

    /**
     * @param callable|null $onControllerEndCallback
     * @return DefaultGenerator
     */
    public function setOnControllerEndCallback(?callable $onControllerEndCallback): DefaultGenerator
    {
        $this->onControllerEndCallback = $onControllerEndCallback;
        return $this;
    }

    /**
     * @param callable|null $onPathStartCallback
     * @return DefaultGenerator
     */
    public function setOnPathStartCallback(?callable $onPathStartCallback): DefaultGenerator
    {
        $this->onPathStartCallback = $onPathStartCallback;
        return $this;
    }

    /**
     * @param callable|null $onPathEndCallback
     * @return DefaultGenerator
     */
    public function setOnPathEndCallback(?callable $onPathEndCallback): DefaultGenerator
    {
        $this->onPathEndCallback = $onPathEndCallback;
        return $this;
    }

    /**
     * @param callable|null $callback
     * @param array $params
     */
    protected function call(?callable $callback, array $params)
    {
        if ($callback === null) return;
        call_user_func_array($callback, $params);
    }

    /**
     * @param ResultSpecification $specification
     * @return OpenApi
     * @throws \ReflectionException
     */
    protected function generateSpecification(ResultSpecification $specification): OpenApi
    {
        $this->call($this->onSpecificationStartCallback, [$specification]);

        $this->currentSpecificationId = $specification->version;

        $openapi = new OpenApi([]);
        $this->currentOpenApi = $openapi;

        $openapi->info = new Info([
            'title' => $specification->description,
            'version' => $specification->version,
        ]);

        $openapi->servers = [];
        foreach ($specification->servers as $server) {
            $openapi->servers[] = new Server([
                'url' => $server->url,
                'description' => $server->description,
            ]);
        }

        $openapi->tags = [];
        foreach ($specification->tags as $tag) {
            $openapi->tags[] = new Tag([
                'name' => $tag->name,
                'description' => $tag->description,
                'externalDocs' => $tag->externalDocs,
            ]);
        }

        $openapi->components = new Components([]);
        $openapi->components->securitySchemes = [];
        foreach ($specification->securitySchemes as $securityScheme) {
            $openapi->components->securitySchemes[] = new SecurityScheme([
                'securityScheme' => $securityScheme->id,
                'type' => $securityScheme->type,
                'description' => $securityScheme->description,
                'name' => $securityScheme->name,
                'in' => $securityScheme->in,
            ]);
        }

        foreach ($specification->paths as $path) {
            $this->call($this->onPathStartCallback, [$path]);

            try {
                $this->generateAnnotationForPath($path);
            } catch (Exception $e) {
                $this->notice('Error when working on '.$specification->version.':'.$path->id
                    .': '.$e->getMessage().' ('.$e->getFile().':'.$e->getLine().')', static::NOTICE_ERROR);
                $this->notice($e->getTraceAsString(), static::NOTICE_ERROR);
            }

            $this->call($this->onPathEndCallback, [$path]);
        }

        $this->call($this->onSpecificationEndCallback, [$specification]);

        return $openapi;
    }

    /**
     * @param ResultPath $path
     */
    protected function generateAnnotationForPath(
        ResultPath $path)
    {
    }
}