<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use Exception;
use OpenApi\Annotations\Components;
use OpenApi\Annotations\ExternalDocumentation;
use OpenApi\Annotations\Info;
use OpenApi\Annotations\OpenApi;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\PathItem;
use OpenApi\Annotations\SecurityScheme;
use OpenApi\Annotations\Server;
use OpenApi\Annotations\Tag;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionException;
use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\Generator\Result\GeneratorResult;
use wapmorgan\OpenApiGenerator\Generator\Result\GeneratorResultSpecification;
use wapmorgan\OpenApiGenerator\ReflectionsCollection;
use wapmorgan\OpenApiGenerator\Scraper\DefaultScrapper;
use wapmorgan\OpenApiGenerator\Scraper\Result\Result;
use wapmorgan\OpenApiGenerator\Scraper\Result\ResultPath;
use wapmorgan\OpenApiGenerator\Scraper\Result\ResultSpecification;

class DefaultGenerator extends ErrorableObject
{
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
     * @var DocBlockFactory
     */
    protected $docBlockFactory;

    /**
     * @var PathDescriber
     */
    private $pathDescriber;

    /**
     * @var TypeDescriber
     */
    private $typeDescriber;

    /**
     * @var ClassDescriber
     */
    private $classDescriber;

    /**
     * DefaultGenerator constructor.
     */
    public function __construct()
    {
        $this->docBlockFactory = DocBlockFactory::createInstance();
        $this->pathDescriber = new PathDescriber($this);
        $this->typeDescriber = new TypeDescriber($this);
        $this->classDescriber = new ClassDescriber($this);
    }

    /**
     * @param \wapmorgan\OpenApiGenerator\Scraper\DefaultScrapper $scraper
     * @return GeneratorResult
     * @throws \ReflectionException
     */
    public function generate(DefaultScrapper $scraper): GeneratorResult
    {
        $scrape_result = $scraper->scrape();

        $this->call($this->onStartCallback, [$scrape_result]);

        $result = new GeneratorResult();

        foreach ($scrape_result->specifications as $specification) {
            $result->specifications[] = new GeneratorResultSpecification([
                'id' => $specification->version,
                'title' => $specification->description,
                'specification' => $this->generateSpecification($specification),
            ]);
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
     * @throws ReflectionException
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
                'externalDocs' => ($tag->externalDocs !== null
                    ? new ExternalDocumentation(['url' => $tag->externalDocs])
                    : null),
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

        $openapi->paths = [];

        foreach ($specification->paths as $path) {
            $this->call($this->onPathStartCallback, [$path]);

            try {
                $openapi->paths[] = $this->generateAnnotationForPath($path);
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
     * @param ResultPath $resultPath
     * @return PathItem
     * @throws ReflectionException
     */
    protected function generateAnnotationForPath(ResultPath $resultPath): PathItem
    {
        $path_reflection = ReflectionsCollection::getMethod($resultPath->actionCallback[0], $resultPath->actionCallback[1]);

        $doc_block = $this->docBlockFactory->create($path_reflection->getDocComment());

        $path = new PathItem([
            'path' => $resultPath->id,
        ]);

        $operation_class = '\OpenApi\Annotations\\'.ucfirst(strtolower($resultPath->httpMethod));

        /** @var Operation $path_method */
        $path_method = $path->{strtolower($resultPath->httpMethod)} = new $operation_class([
            'operationId' => $resultPath->id.'-'.$resultPath->httpMethod,
            'summary' => $doc_block->getSummary(),
            'tags' => [],
        ]);

        $this->pathDescriber->generatePathDescription($path_method, $doc_block);

        foreach ($resultPath->tags as $tag) {
            $path_method->tags[] = $tag;
        }

        if (!empty($resultPath->securitySchemes)) {
            $path_method->security = [];

            foreach ($resultPath->securitySchemes as $securityScheme) {
                $path_method->security[] = [$securityScheme => []];
            }
        }

        $path_method->responses = [
            $this->pathDescriber->generationPathMethodResponses($path_reflection, $doc_block, $resultPath->pathResultWrapper)
        ];
        $path_method->parameters = $this->pathDescriber->generatePathOperationParameters($path_reflection, $doc_block);

        return $path;
    }

    /**
     * @return DocBlockFactory
     */
    public function getDocBlockFactory(): DocBlockFactory
    {
        return $this->docBlockFactory;
    }

    /**
     * @return PathDescriber
     */
    public function getPathDescriber(): PathDescriber
    {
        return $this->pathDescriber;
    }

    /**
     * @return TypeDescriber
     */
    public function getTypeDescriber(): TypeDescriber
    {
        return $this->typeDescriber;
    }

    /**
     * @return ClassDescriber
     */
    public function getClassDescriber(): ClassDescriber
    {
        return $this->classDescriber;
    }
}
