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
use phpDocumentor\Reflection\DocBlock\Tags\InvalidTag;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionException;
use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\ReflectionsCollection;
use wapmorgan\OpenApiGenerator\Scraper\Endpoint;
use wapmorgan\OpenApiGenerator\Scraper\SecurityScheme\ApiKeySecurityScheme;
use wapmorgan\OpenApiGenerator\Scraper\SecurityScheme\HttpSecurityScheme;
use wapmorgan\OpenApiGenerator\Scraper\SecurityScheme\OAuth2SecurityScheme;
use wapmorgan\OpenApiGenerator\Scraper\SecurityScheme\OpenIdConnectSecurityScheme;
use wapmorgan\OpenApiGenerator\Scraper\Specification;
use wapmorgan\OpenApiGenerator\ScraperSkeleton;

class DefaultGenerator extends ErrorableObject
{
    public const CHANGE_GET_TO_POST_FOR_COMPLEX_PARAMETERS = 1;
    public const TREAT_COMPLEX_ARGUMENTS_AS_BODY = 2;
    public const TREAT_EXTRACTED_ARGUMENTS_AS_BODY = 5;
    public const PARSE_PARAMETERS_FROM_ENDPOINT = 3;
    public const PARSE_PARAMETERS_FORMAT_DESCRIPTION = 4;

    protected $settings = [
        self::CHANGE_GET_TO_POST_FOR_COMPLEX_PARAMETERS => false,
        self::TREAT_COMPLEX_ARGUMENTS_AS_BODY => false,
        self::TREAT_EXTRACTED_ARGUMENTS_AS_BODY => false,
        self::PARSE_PARAMETERS_FROM_ENDPOINT => false,
        self::PARSE_PARAMETERS_FORMAT_DESCRIPTION => false,
    ];

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
     * @param \wapmorgan\OpenApiGenerator\ScraperSkeleton $scraper
     * @return GeneratorResultSpecification[]
     * @throws \ReflectionException
     */
    public function generate(ScraperSkeleton $scraper): array
    {
        // initialize generator
        $this->applySettings($scraper->getGeneratorSettings());
        $this->pathDescriber->setArgumentExtractors($scraper->getArgumentExtractors());
        $this->pathDescriber->setCommonParameterDescription($scraper->getCommonParametersDescription());
        $this->pathDescriber->setCustomFormats($scraper->getCustomFormats());
        $this->classDescriber->setClassDescribingOptions($scraper->getClassDescribingOptions());

        // get information about endpoints
        $scrape_result = $scraper->scrape();

        $this->call($this->onStartCallback, [$scrape_result]);

        $result = [];
        foreach ($scrape_result as $specification) {
            $result[] = new GeneratorResultSpecification([
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
     * Sets callable to invoke on new specification progress start
     *
     * Data passed to callable: `ResultSpecification $spec`
     * @param callable|null $onSpecificationStartCallback
     * @return DefaultGenerator
     */
    public function setOnSpecificationStartCallback(?callable $onSpecificationStartCallback): DefaultGenerator
    {
        $this->onSpecificationStartCallback = $onSpecificationStartCallback;
        return $this;
    }

    /**
     * Sets callable to invoke on current specification progress finish
     *
     * Data passed to callable: `ResultSpecification $spec`
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
     *
     * Sets callable to invoke on new path progress start
     *
     * Data passed to callable: `ResultPath $path, ResultSpecification $specification`
     * @param callable|null $onPathStartCallback
     * @return DefaultGenerator
     */
    public function setOnPathStartCallback(?callable $onPathStartCallback): DefaultGenerator
    {
        $this->onPathStartCallback = $onPathStartCallback;
        return $this;
    }

    /**
     * Sets callable to invoke on current path progress finish
     *
     * Data passed to callable: `ResultPath $path, ResultSpecification $specification`
     * @param callable|null $onPathEndCallback
     * @return DefaultGenerator
     */
    public function setOnPathEndCallback(?callable $onPathEndCallback): DefaultGenerator
    {
        $this->onPathEndCallback = $onPathEndCallback;
        return $this;
    }

    /**
     * @param int $setting
     * @param bool $value
     */
    public function changeSetting(int $setting, bool $value): void
    {
        if (!isset($this->settings[$setting])) {
            throw new \InvalidArgumentException('Setting '.$setting.' does not exist!');
        }

        $this->settings[$setting] = $value;
    }

    public function applySettings(array $settings)
    {
        foreach ($settings as $setting => $value)
        {
            $this->changeSetting($setting, $value);
        }
    }

    /**
     * @param int $setting
     * @return bool
     */
    public function getSetting(int $setting): bool
    {
        if (!isset($this->settings[$setting])) {
            throw new \InvalidArgumentException('Setting '.$setting.' does not exist!');
        }
        return $this->settings[$setting];
    }

    /**
     * @param callable|null $callback
     * @param array $params
     */
    protected function call(?callable $callback, array $params): void
    {
        if ($callback === null) return;
        call_user_func_array($callback, $params);
    }

    /**
     * @param \wapmorgan\OpenApiGenerator\Scraper\Specification $specification
     * @return OpenApi
     * @throws ReflectionException
     */
    protected function generateSpecification(Specification $specification): OpenApi
    {
        $this->call($this->onSpecificationStartCallback, [$specification]);

        $this->currentSpecificationId = $specification->version;

        $openapi = new OpenApi([]);
        $this->currentOpenApi = $openapi;

        $openapi->info = new Info([
            'title' => $specification->title,
            'description' => $specification->description,
            'version' => $specification->version,
        ]);

        if ($specification->externalDocs !== null) {
            $openapi->externalDocs = new ExternalDocumentation(['url' => $specification->externalDocs]);
        }

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
                    : \OpenApi\Generator::UNDEFINED),
            ]);
        }

        $openapi->components = new Components([]);
        $openapi->components->securitySchemes = [];
        foreach ($specification->securitySchemes as $securityScheme) {
            switch (get_class($securityScheme)) {
                case ApiKeySecurityScheme::class:
                    $openapi->components->securitySchemes[] = new SecurityScheme([
                         'securityScheme' => $securityScheme->id,
                         'type' => $securityScheme->type,
                         'description' => $securityScheme->description,
                         'name' => $securityScheme->name,
                         'in' => $securityScheme->in,
                     ]);
                    break;

                case HttpSecurityScheme::class:
                    $openapi->components->securitySchemes[] = new SecurityScheme([
                         'securityScheme' => $securityScheme->id,
                         'type' => $securityScheme->type,
                         'scheme' => $securityScheme->scheme,
                         'bearerFormat' => $securityScheme->bearerFormat !== null
                             ? $securityScheme->bearerFormat
                             : \OpenApi\Generator::UNDEFINED,
                     ]);
                    break;

                case OAuth2SecurityScheme::class:
                    $openapi->components->securitySchemes[] = new SecurityScheme([
                         'securityScheme' => $securityScheme->id,
                         'description' => $securityScheme->description,
                         'flows' => $securityScheme->flows,
                     ]);
                    break;

                case OpenIdConnectSecurityScheme::class:
                    $openapi->components->securitySchemes[] = new SecurityScheme([
                         'securityScheme' => $securityScheme->id,
                         'openIdConnectUrl' => $securityScheme->openIdConnectUrl,
                     ]);
                    break;
            }

        }

        $openapi->paths = [];

        $paths = $errored_paths = 0;

        foreach ($specification->endpoints as $path) {
            $this->call($this->onPathStartCallback, [$path, $specification]);

            try {
                $openapi->paths[] = $this->generateAnnotationForPath($path);
                $paths++;
            } catch (Exception $e) {
                $errored_paths++;
                $this->notice('Error when working on '.$specification->version.$path->id
                    .': '.$e->getMessage().' ('.$e->getFile().':'.$e->getLine().')', static::NOTICE_ERROR);
                $this->notice($e->getTraceAsString(), static::NOTICE_INFO);
            }

            $this->call($this->onPathEndCallback, [$path, $specification]);
        }
        $this->notice('Generated '.$paths.' paths for '.$specification->version
            .($errored_paths > 0 ? ', '.$errored_paths.' errored' : null), static::NOTICE_IMPORTANT);

        $this->call($this->onSpecificationEndCallback, [$specification]);

        return $openapi;
    }

    /**
     * @param Endpoint $resultPath
     * @return PathItem
     * @throws ReflectionException
     */
    protected function generateAnnotationForPath(Endpoint $resultPath): PathItem
    {
        $callback_class = is_object($resultPath->callback[0]) ? get_class($resultPath->callback[0]) : $resultPath->callback[0];
        $path_reflection = ReflectionsCollection::getMethod($callback_class, $resultPath->callback[1]);

        $path_doc = $path_reflection->getDocComment();

        $path = new PathItem([
            'path' => $resultPath->id,
        ]);

        if ($path_doc === false) {
            $this->notice('Path '.$resultPath->id.' has no doc at all', self::NOTICE_WARNING);
            $doc_block = null;
        } else {
            $doc_block = $this->docBlockFactory->create($path_doc);
        }

        if ($this->settings[self::TREAT_COMPLEX_ARGUMENTS_AS_BODY]) {
            $path_request_body = $this->pathDescriber->generatePathOperationBody(
                $path_reflection,
                $doc_block,
                $this->settings[self::TREAT_EXTRACTED_ARGUMENTS_AS_BODY]);

            // if request has body and method set to GET, change it to POST
            if ($path_request_body !== null
                && $this->settings[self::CHANGE_GET_TO_POST_FOR_COMPLEX_PARAMETERS]
                && strcasecmp($resultPath->httpMethod, 'get') === 0) {
                $this->notice('Http method of ' . $resultPath->id . ' changed from "' . $resultPath->httpMethod . '" to "post" because of request body', self::NOTICE_INFO);
                $resultPath->httpMethod = 'post';
            }
        }

        $operation_class = '\OpenApi\Annotations\\'.ucfirst(strtolower($resultPath->httpMethod));
        /** @var Operation $path_method */
        $path_method = $path->{strtolower($resultPath->httpMethod)} = new $operation_class([
            'operationId' => $resultPath->id.'-'.$resultPath->httpMethod,
            'summary' => $doc_block ? $doc_block->getSummary() : null,
            'tags' => $resultPath->tags,
        ]);

        $this->pathDescriber->generatePathDescription($path_method, $doc_block);

        if (!empty($resultPath->securitySchemes)) {
            $path_method->security = [];

            foreach ($resultPath->securitySchemes as $securityScheme) {
                $path_method->security[] = [$securityScheme => []];
            }
        }

        // generate responses from @return
        if (!isset($resultPath->result)) {
            $path_response_schemas = $this->pathDescriber->generatePathMethodResponsesFromDocBlock($path_reflection, $doc_block, $resultPath->resultWrapper);
        } else { // generate responses from passed $pathResult
            $path_response_schemas = $this->pathDescriber->generationPathMethodResponseFromType($path_reflection, $resultPath->result, $resultPath->resultWrapper);
        }

        if (!empty($path_response_schemas)) {
            $path_method->responses = [
                $this->pathDescriber->combineResponseWithWrapper(
                    $path_response_schemas,
                    $path_reflection,
                    $resultPath->resultWrapper
                )
            ];
        }

        $path_method->parameters = $this->pathDescriber->generatePathOperationParameters(
            $path_reflection,
            $doc_block,
            $this->settings[self::TREAT_COMPLEX_ARGUMENTS_AS_BODY] && $this->settings[self::TREAT_EXTRACTED_ARGUMENTS_AS_BODY]);

        if ($this->settings[self::PARSE_PARAMETERS_FROM_ENDPOINT]
            && preg_match_all('~\{([a-z]+)\}~i', $resultPath->id, $matches) > 0) {
            foreach ($matches[1] as $match) {
                foreach ($path_method->parameters as $parameter) {
                    if ($parameter->name === $match) {
                        $parameter->in = 'path';
                    }
                }
            }
        }

        if (isset($path_request_body)) {
            $path_method->requestBody = $path_request_body;
        }

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

    public function onInvalidTag(InvalidTag $tag, $location = null)
    {
        $this->notice('Tag "@' . $tag->getName() . '"' . (!empty($location) ? ' of "'.$location.'"' : null)
                      . ' is invalid: '.$tag->getException()->getMessage(),
         ErrorableObject::NOTICE_ERROR);
    }
}
