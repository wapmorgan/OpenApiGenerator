<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use OpenApi\Annotations\ExternalDocumentation;
use OpenApi\Annotations\MediaType;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\Property;
use OpenApi\Annotations\RequestBody;
use OpenApi\Annotations\Response;
use OpenApi\Annotations\Schema;
use OpenApi\Generator;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\Tags\InvalidTag;
use phpDocumentor\Reflection\DocBlock\Tags\Link;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\Scraper\Endpoint;
use wapmorgan\OpenApiGenerator\Scraper\PathResultWrapper;

use const OpenApi\UNDEFINED;

class PathDescriber
{
    public const
        SKIP_ARGUMENTS = 1;
    /**
     * @var DefaultGenerator
     */
    protected $generator;

    /**
     * @var array
     */
    protected $basicFormats = [
        'string' => ['date', 'date-time', 'password', 'byte', 'binary'],
        'integer' => ['float', 'double', 'int32', 'int64'],
    ];

    /**
     * @var array
     */
    protected $customFormats = [];

    /**
     * @var array
     */
    protected $commonParametersDescription = [];

    /**
     * @var array
     */
    protected $argumentExtractors = [];

    /**
     * TypeDescriber constructor.
     * @param DefaultGenerator $generator
     */
    public function __construct(DefaultGenerator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * @param string $name
     * @param $description
     * @return $this
     */
    public function setCommonParameterDescription(array $descriptions): PathDescriber
    {
        $this->commonParametersDescription = $descriptions;
        return $this;
    }

    /**
     * @param string $formatName
     * @param array $formatConfig
     * @return $this
     */
    public function setCustomFormats(array $customFormats): PathDescriber
    {
        $this->customFormats = $customFormats;
        return $this;
    }

    /**
     * @param array $extractors
     * @return $this
     */
    public function setArgumentExtractors(array $extractors)
    {
        $this->argumentExtractors = $extractors;
        return $this;
    }

    /**
     * @param DocBlock $docBlock
     * @param Operation $pathOperation
     * @return void
     */
    public function generatePathDescription(
        ReflectionMethod $reflectionMethod,
        Operation $pathOperation,
        ?DocBlock $docBlock,
        bool $writePathCallback,
        bool $writePathFile
    ): void
    {
        $description = [];

        if ($writePathCallback) {
            $description[] = 'Callback: `' . $reflectionMethod->getDeclaringClass()->getName() . '::' . $reflectionMethod->getName() . '`';
            $description[] = null;
        }
        if ($writePathFile) {
            $description[] = 'File: *' . ($writePathCallback ? '*' : null) . $reflectionMethod->getDeclaringClass()->getFileName() . '*' . ($writePathCallback ? '*' : null);
            $description[] = null;
        }

        if ($docBlock !== null && !empty($docBlock->getDescription())) {
            $description[] = implode("\n", array_map(static function ($line) {
                return ltrim(rtrim($line), '*');
            },
                explode("\n", str_replace('"', '\'', $docBlock->getDescription()))
            ));
        }

        if ($docBlock !== null && !empty($link_tags = $docBlock->getTagsByName('link'))) {
            if (count($link_tags) > 1) {
                /** @var Link $link_tag */
                foreach ($link_tags as $link_tag) {
                    if ($link_tag instanceof InvalidTag) {
                        $this->generator->onInvalidTag($link_tag, $pathOperation->path);
                        continue;
                    }
                    $description[] = $link_tag->getLink() . (!empty($link_tag->getDescription()) ? $link_tag->getDescription() : '');
                }
            } else {
                $link_tag = $link_tags[0];
                if ($link_tag instanceof InvalidTag) {
                    $this->generator->notice('Tag "' . (string)$link_tag . '" of "'
                     . $pathOperation->path . '" is invalid: '.$link_tag->getException()->getMessage(),
                     ErrorableObject::NOTICE_ERROR);
                } else {
                    $pathOperation->externalDocs = new ExternalDocumentation([
                        'url' => $link_tags[0]->getLink(),
                         'description' => (!empty(
                         $link_tags[0]->getDescription()
                         ) ? (string)$link_tags[0]->getDescription(
                         ) : ''),
                     ]);
                }
            }
        }

        $pathOperation->description = implode("\n", $description);
    }

    /**
     * Return the explicit location from a PHPDoc parameter description such as `[header]`.
     */
    public function getExplicitParameterLocation(?Param $parameter): ?string
    {
        if ($parameter === null || $parameter->getDescription() === null) {
            return null;
        }

        if (!preg_match('/^\[([a-z]+)(?::([^\]]+))?\](?:\s|$)/i', trim((string)$parameter->getDescription()), $matches)) {
            return null;
        }

        $location = strtolower($matches[1]);
        return in_array($location, ['query', 'path', 'header', 'cookie', 'body'], true)
            ? $location
            : null;
    }

    public function getExplicitParameterName(?Param $parameter): ?string
    {
        if ($parameter === null || $parameter->getDescription() === null) {
            return null;
        }

        if (preg_match('/^\[[a-z]+:([^\]]+)\](?:\s|$)/i', trim((string)$parameter->getDescription()), $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public function hasExplicitBodyParameter(?DocBlock $docBlock): bool
    {
        if ($docBlock === null) {
            return false;
        }

        foreach ($docBlock->getTagsByName('param') as $parameter) {
            if ($parameter instanceof Param && $this->getExplicitParameterLocation($parameter) === 'body') {
                return true;
            }
        }

        foreach ($this->getLocationParameterTags($docBlock) as $parameter) {
            $definition = $this->parseApiParameterTag($parameter, false);
            if ($definition !== null && $definition['location'] === 'body') {
                return true;
            }
        }

        return false;
    }

    /** @return Tag[] */
    protected function getLocationParameterTags(?DocBlock $docBlock): array
    {
        if ($docBlock === null) {
            return [];
        }

        $tags = [];
        foreach (['apiParameter', 'queryParam', 'pathParam', 'headerParam', 'cookieParam', 'bodyParam', 'requestBody'] as $tagName) {
            foreach ($docBlock->getTagsByName($tagName) as $tag) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /** Parse a location-specific parameter tag or the legacy @apiParameter tag. */
    protected function parseApiParameterTag(Tag $tag, bool $reportErrors = true): ?array
    {
        if ($tag instanceof InvalidTag) {
            if ($reportErrors) {
                $this->generator->onInvalidTag($tag);
            }
            return null;
        }

        $tagName = strtolower($tag->getName());
        $tagLocations = [
            'queryparam' => 'query',
            'pathparam' => 'path',
            'headerparam' => 'header',
            'cookieparam' => 'cookie',
            'bodyparam' => 'body',
        ];
        $definition = trim((string)$tag);
        $wireName = null;
        $required = null;
        $requiredSpecified = false;
        $wholeBody = false;

        if ($tagName === 'requestbody') {
            $matched = preg_match('/^(.+?)\s+(required|optional)(?:\s+(.*))?$/is', $definition, $matches);
            $type = isset($matches[1]) ? trim($matches[1]) : '';
            $name = 'body';
            $location = 'body';
            $tail = isset($matches[3]) ? trim($matches[3]) : '';
            $required = isset($matches[2]) && strtolower($matches[2]) === 'required';
            $requiredSpecified = $matched === 1;
            $wholeBody = true;
        } elseif (isset($tagLocations[$tagName])) {
            $location = $tagLocations[$tagName];
            $matched = preg_match('/^(?:(.*?)\s+)?\$([a-z_][a-z0-9_]*)(?:\s+(.*))?$/is', $definition, $matches);
            $type = isset($matches[1]) ? trim($matches[1]) : '';
            $name = $matches[2] ?? null;
            $tail = isset($matches[3]) ? trim($matches[3]) : '';
        } elseif ($tagName === 'apiparameter') {
            $matched = preg_match(
                '/^(?:(.*?)\s+)?\$([a-z_][a-z0-9_]*)\s+\[([a-z]+)(?::([^\]]+))?\](?:\s+(.*))?$/is',
                $definition,
                $matches
            );
            $type = isset($matches[1]) ? trim($matches[1]) : '';
            $name = $matches[2] ?? null;
            $location = isset($matches[3]) ? strtolower($matches[3]) : null;
            $wireName = isset($matches[4]) ? trim($matches[4]) : null;
            $tail = isset($matches[5]) ? trim($matches[5]) : '';
            $matched = $matched === 1
                && in_array($location, ['query', 'path', 'header', 'cookie', 'body'], true);
        } else {
            return null;
        }

        if (!$matched) {
            if ($reportErrors) {
                $this->generator->notice(
                    'Invalid @' . $tagName . ' syntax: ' . $definition,
                    ErrorableObject::NOTICE_ERROR
                );
            }
            return null;
        }

        $format = null;
        while (preg_match('/^(required|optional|format=[^\s]+|name=[^\s]+)(?:\s+|$)/i', $tail, $optionMatch)) {
            $option = $optionMatch[1];
            if (strcasecmp($option, 'required') === 0) {
                $required = true;
                $requiredSpecified = true;
            } elseif (strcasecmp($option, 'optional') === 0) {
                $required = false;
                $requiredSpecified = true;
            } elseif (stripos($option, 'format=') === 0) {
                $format = substr($option, strlen('format='));
            } elseif (stripos($option, 'name=') === 0) {
                $wireName = substr($option, strlen('name='));
            }
            $tail = trim(substr($tail, strlen($optionMatch[0])));
        }

        return [
            'tagName' => $tagName,
            'wholeBody' => $wholeBody || $tagName === 'apiparameter',
            'type' => $type !== '' ? $type : null,
            'name' => $name,
            'location' => $location,
            'wireName' => $wireName,
            'required' => $required,
            'requiredSpecified' => $requiredSpecified,
            'format' => $format,
            'description' => $tail,
        ];
    }

    /**
     * Resolve location tags to run() arguments when possible; otherwise they describe standalone request values.
     *
     * @return array{bound: array<string, array>, external: array<int, array>}
     */
    protected function resolveLocationParameters(
        ?DocBlock $docBlock,
        ReflectionFunctionAbstract $actionReflection,
        array $docParameters,
        bool $reportErrors = true
    ): array {
        $bound = [];
        $external = [];
        $reflectionParameters = [];
        foreach ($actionReflection->getParameters() as $parameter) {
            $reflectionParameters[$parameter->getName()] = $parameter;
        }

        foreach ($this->getLocationParameterTags($docBlock) as $tag) {
            $definition = $this->parseApiParameterTag($tag, $reportErrors);
            if ($definition === null) {
                continue;
            }

            $name = $definition['name'];
            $reflectionParameter = $reflectionParameters[$name] ?? null;
            $docParameter = $docParameters[$name] ?? null;
            if ($definition['type'] === null) {
                $definition['type'] = $docParameter !== null
                    ? (string)$docParameter->getType()
                    : ($reflectionParameter !== null && $reflectionParameter->getType() !== null
                        ? (string)$reflectionParameter->getType()
                        : null);
            }
            if ($definition['type'] === null || $definition['type'] === '') {
                if ($reportErrors) {
                    $this->generator->notice(
                        'API parameter $' . $name . ' has no type in its tag, @param, or PHP signature',
                        ErrorableObject::NOTICE_ERROR
                    );
                }
                continue;
            }

            if ($definition['description'] === '' && $docParameter !== null) {
                $definition['description'] = $this->getParameterDescription($docParameter) ?? '';
            }
            if ($definition['required'] === null) {
                $definition['required'] = $definition['location'] === 'path'
                    || ($reflectionParameter !== null && !$reflectionParameter->isOptional());
            }
            if ($definition['location'] === 'path') {
                $definition['required'] = true;
            }

            if ($reflectionParameter !== null) {
                $bound[$name][] = $definition;
            } else {
                $external[] = $definition;
            }
        }

        foreach ($bound as $name => $definitions) {
            if (count($definitions) > 1) {
                foreach ($definitions as &$definition) {
                    if (!$definition['requiredSpecified'] && $definition['location'] !== 'path') {
                        // Multiple locations for one argument describe alternative accepted sources.
                        $definition['required'] = false;
                    }
                }
                unset($definition);
                $bound[$name] = $definitions;
            }
        }

        return ['bound' => $bound, 'external' => $external];
    }

    protected function getParameterDescription(?Param $parameter): ?string
    {
        if ($parameter === null || $parameter->getDescription() === null) {
            return null;
        }

        $description = trim((string)$parameter->getDescription());
        if ($this->getExplicitParameterLocation($parameter) !== null) {
            $description = preg_replace('/^\[[a-z]+(?::[^\]]+)?\](?:\s+|$)/i', '', $description, 1);
        }

        return trim($description);
    }

    protected function hasInvalidParameterLocation(?Param $parameter): bool
    {
        return $parameter !== null
            && $parameter->getDescription() !== null
            && preg_match('/^\[([^\]:]+)(?::[^\]]+)?\]/', trim((string)$parameter->getDescription()), $matches) === 1
            && !in_array(strtolower($matches[1]), ['query', 'path', 'header', 'cookie', 'body'], true);
    }

    /**
     * @param ReflectionFunctionAbstract $actionReflection
     * @param DocBlock|null $docBlock
     * @param PathResultWrapper|null $pathResultWrapper
     * @return array
     * @throws ReflectionException
     */
    public function generatePathMethodResponsesFromDocBlock(
        ReflectionFunctionAbstract $actionReflection,
        ?DocBlock $docBlock,
        ?PathResultWrapper $pathResultWrapper
    ): ?array
    {
        $declaring_class = $actionReflection instanceof ReflectionMethod
            ? $actionReflection->getDeclaringClass()->getName()
            : null;

        $responses_schemas = [];
        if ($docBlock !== null && $docBlock->hasTag('return')) {
            /** @var Return_ $return_block */
            $return_block = $docBlock->getTagsByName('return')[0];

            if ($return_block instanceof InvalidTag) {
                $this->generator->onInvalidTag($return_block,
                                               $actionReflection->getDeclaringClass()->getName()
                                               .':'.$actionReflection->getName().'()'
                );
                return null;
            }

            $return_string = (string)$return_block->getType();
            $return_description = $return_block->getDescription() !== null
                ? trim((string)$return_block->getDescription())
                : null;

            // Поддержка нескольких возвращемых типов
            foreach ($this->generator->getTypeDescriber()->splitTypeVariants($return_string) as $return_type) {
                if ($return_type === 'null'
                    || ($responses_schema = $this->generateSchemaForPathResultBlock($declaring_class, $return_type, $pathResultWrapper)) === null)
                    continue;
                if ($return_description !== null && $return_description !== '') {
                    $responses_schema->description = $return_description;
                }
                $responses_schemas[] = $responses_schema;
            }
            return $responses_schemas;
        }

        return null;
    }

    /**
     * @param ReflectionMethod $actionReflection
     * @param string|object|null $type
     * @param PathResultWrapper|null $pathResultWrapper
     * @throws ReflectionException
     */
    public function generatePathMethodResponseFromType(
        ReflectionMethod $actionReflection,
        $type,
        ?PathResultWrapper $pathResultWrapper
    )
    {
        $declaring_class = $actionReflection->getDeclaringClass()->getName();

        $responses_schemas = [];
        if (is_string($type)) {
            // Поддержка нескольких возвращемых типов
            foreach (explode('|', $type) as $return_type) {
                if ($return_type === 'null'
                    || ($responses_schema = $this->generateSchemaForPathResultBlock($declaring_class, $return_type, $pathResultWrapper)) === null)
                    continue;
                $responses_schemas[] = $responses_schema;
            }
            return $responses_schemas;
        } else if (is_object($type)) {
            return [$this->generateSchemaForPathResultObject($declaring_class, $type, $pathResultWrapper)];
        } else {
            return null;
        }
    }

    /**
     * @param array|null $schemas
     * @param ReflectionMethod $actionReflection
     * @param PathResultWrapper|null $pathResultWrapper
     * @param string $responseContentType
     * @return null
     * @throws ReflectionException
     */
    public function combineResponseWithWrapper(
        ?array $schemas,
        ReflectionMethod $actionReflection,
        ?PathResultWrapper $pathResultWrapper,
        string $responseContentType = 'application/json'
    ) {
        $declaring_class = $actionReflection->getDeclaringClass()->getName();
        if ($pathResultWrapper !== null) {
            $result_wrapper_schema = $this->generateSchemaForPathResultBlock($declaring_class, $pathResultWrapper->wrapperClass, null);
        }

        if (!empty($schemas)) {
            // integrate wrapper in all responses
            if (isset($result_wrapper_schema)) {
                foreach ($schemas as $i => &$result_combination) {
                    $result_combination = new Schema([
                        'allOf' => [
                            $result_wrapper_schema,
                            $result_combination,
                        ],
                    ]);
                }
            }
        } else if (isset($result_wrapper_schema)) {
            $schemas = [$result_wrapper_schema];
        } else {
            return null;
        }

        if (count($schemas) === 1) {
            $result_block = current($schemas);
        } else {
            $result_block = new Schema([
                'oneOf' => $schemas,
            ]);
        }

        // Generation @OA\Response
        return new Response([
            'response' => 200,
            'description' => 'Successful response',
            'content' => [
                new MediaType([
                    'mediaType' => $responseContentType,
                    'schema' => $result_block,
                ])
            ],
        ]);
    }

    public function generateRawResponse(string $responseContentType): Response
    {
        return new Response([
            'response' => 200,
            'description' => 'Successful response',
            'content' => [
                new MediaType([
                    'mediaType' => $responseContentType,
                ])
            ],
        ]);
    }

    /**
     * @param string $declaringClass
     * @param string $returnBlock
     * @param PathResultWrapper|null $pathResultWrapper
     * @return Schema
     * @throws ReflectionException
     */
    protected function generateSchemaForPathResultBlock(
        string $declaringClass,
        string $returnBlock,
        ?PathResultWrapper $pathResultWrapper = null
    ): ?Schema {
        $schema = $this->generateSchemaForReturnBlock($declaringClass, $returnBlock);
        if ($schema !== null && $pathResultWrapper !== null) {
            $schema = $pathResultWrapper->wrapResultInSchema($schema);
        }
        return $schema;
    }

    /**
     * @param string $declaringClass
     * @param object $returnObject
     * @param PathResultWrapper|null $pathResultWrapper
     * @return Schema
     * @throws ReflectionException
     */
    protected function generateSchemaForPathResultObject(
        string $declaringClass,
        object $returnObject,
        ?PathResultWrapper $pathResultWrapper = null
    ): Schema {
        $schema = $this->generateSchemaForReturnObject($declaringClass, $returnObject);
        if ($pathResultWrapper !== null) {
            $schema = $pathResultWrapper->wrapResultInSchema($schema);
        }
        return $schema;
    }

    /**
     * @param string $declaringClass
     * @param string $returnBlock
     * @return Schema
     * @throws ReflectionException
     */
    protected function generateSchemaForReturnBlock(
        string $declaringClass,
        string $returnBlock
    ): ?Schema {
        $resultSpec = ltrim($returnBlock, '\\');
        return $this->generator->getTypeDescriber()->generateSchemaForType($declaringClass, $resultSpec, null);
    }

    /**
     * @param string $declaringClass
     * @param object $returnObject
     * @return Schema
     */
    public function generateSchemaForReturnObject(
        string $declaringClass,
        object $returnObject
    ): Schema {
        $result_type_description = null;
        return $this->generator->getTypeDescriber()->generateSchemaForObject($returnObject);
    }

    /**
     * @param ReflectionMethod $actionReflection
     * @param DocBlock|null $docBlock
     * @return Parameter[]
     * @throws ReflectionException
     */
    public function generatePathOperationParameters(
        ReflectionFunctionAbstract $actionReflection,
        ?DocBlock $docBlock,
        bool $treatExtractedArgumentsAsBody,
        array $pathParameterNames = []
    ): array
    {
        /** @var array<string, Param> $doc_block_parameters */
        $doc_block_parameters = [];
        $parameters_enums = $parameters_examples = $parameters_formats = [];

        $location = $actionReflection instanceof ReflectionMethod
            ? $actionReflection->getDeclaringClass()->getName().':'.$actionReflection->getName().'()'
            : '{Closure:' . $actionReflection->getFileName() . ':' . $actionReflection->getStartLine() . '}';

        // phpDoc arguments
        if ($docBlock !== null) {
            /** @var Param $action_parameter */
            foreach ($docBlock->getTagsByName('param') as $action_parameter) {
                if ($action_parameter instanceof InvalidTag)
                    $this->generator->onInvalidTag($action_parameter, $location);
                else
                    $doc_block_parameters[$action_parameter->getVariableName()] = $action_parameter;
            }

            /** @var Tag $action_parameter_enum */
            foreach ($docBlock->getTagsByName('paramEnum') as $action_parameter_enum) {
                if ($action_parameter_enum instanceof InvalidTag) {
                    $this->generator->onInvalidTag($action_parameter_enum, $location);
                    continue;
                }

                $enum_string = $action_parameter_enum->getDescription() !== null
                    ? (string)$action_parameter_enum->getDescription()
                    : null;
                if (strpos($enum_string, ' ') === false) {
                    $this->generator->notice('Param enum '.$enum_string.' is incomplete', DefaultGenerator::NOTICE_WARNING);
                    continue;
                }

                list($enum_param, $enum_list) = explode(' ', $enum_string, 2);
                $parameters_enums[ltrim($enum_param, '$')] = explode('|', $enum_list);
            }

            /** @var Tag $action_parameter_example */
            foreach ($docBlock->getTagsByName('paramExample') as $action_parameter_example) {
                if ($action_parameter_example instanceof InvalidTag) {
                    $this->generator->onInvalidTag($action_parameter_example, $location);
                    continue;
                }

                $example_string = $action_parameter_example->getDescription() !== null
                    ? (string)$action_parameter_example->getDescription()
                    : null;
                if (strpos($example_string, ' ') === false) {
                    $this->generator->notice('Param example '.$example_string.' is incomplete', DefaultGenerator::NOTICE_WARNING);
                    continue;
                }

                list($example_param, $example_value) = explode(' ', $example_string, 2);
                $parameters_examples[ltrim($example_param, '$')][] = $example_value;
            }

            /** @var Tag $action_parameter_example */
            foreach ($docBlock->getTagsByName('paramFormat') as $action_parameter_format) {
                if ($action_parameter_format instanceof InvalidTag) {
                    $this->generator->onInvalidTag($action_parameter_format, $location);
                    continue;
                }

                $format_string = $action_parameter_format->getDescription() !== null
                    ? (string)$action_parameter_format->getDescription()
                    : null;
                if (strpos($format_string, ' ') === false) {
                    $this->generator->notice('Param format '.$format_string.' is incomplete', DefaultGenerator::NOTICE_WARNING);
                    continue;
                }

                list($format_param, $format_value) = explode(' ', $format_string, 2);
                $parameters_formats[ltrim($format_param, '$')][] = $format_value;
            }
        }

        $locationParameters = $this->resolveLocationParameters($docBlock, $actionReflection, $doc_block_parameters);

        $parameters = [];
        $required = [];

        // Generation @OA\Parameter's
        // php arguments
        if ($actionReflection->getNumberOfParameters() > 0) {
            foreach ($actionReflection->getParameters() as $parameter) {
                $docParameter = $doc_block_parameters[$parameter->getName()] ?? null;
                if (isset($locationParameters['bound'][$parameter->getName()])) {
                    foreach ($locationParameters['bound'][$parameter->getName()] as $definition) {
                        $wireName = $definition['wireName'] ?: $definition['name'];
                        $isPathParameter = in_array($wireName, $pathParameterNames, true);
                        if ($isPathParameter && $definition['location'] !== 'path') {
                            $this->generator->notice(
                                'Path parameter $' . $parameter->getName() . ' cannot use @' . $definition['tagName']
                                . '; using path',
                                ErrorableObject::NOTICE_ERROR
                            );
                            $definition['location'] = 'path';
                            $definition['required'] = true;
                        }

                        if ($definition['location'] !== 'body') {
                            $apiParameter = $this->generateSchemaForApiParameter(
                                $actionReflection->getDeclaringClass()->getName(),
                                $definition,
                                $parameters_formats[$parameter->getName()] ?? null,
                                $parameters_enums[$parameter->getName()] ?? null,
                                $parameters_examples[$parameter->getName()] ?? null
                            );
                            if ($apiParameter !== null) {
                                $this->appendUniqueParameter($parameters, $apiParameter);
                            }
                        }
                    }
                    continue;
                }

                if ($this->hasInvalidParameterLocation($docParameter)) {
                    $this->generator->notice(
                        'Invalid @param location for $' . $parameter->getName() . ': ' . (string)$docParameter->getDescription(),
                        ErrorableObject::NOTICE_ERROR
                    );
                }
                $explicitLocation = $this->getExplicitParameterLocation($docParameter);
                $parameterName = $this->getExplicitParameterName($docParameter) ?? $parameter->getName();
                $isPathParameter = in_array($parameterName, $pathParameterNames, true);
                if ($isPathParameter && $explicitLocation !== null && $explicitLocation !== 'path') {
                    $this->generator->notice(
                        'Path parameter $' . $parameter->getName() . ' cannot use @param location [' . $explicitLocation . ']; using path',
                        ErrorableObject::NOTICE_ERROR
                    );
                }
                $parameterLocation = $isPathParameter ? 'path' : $explicitLocation;

                $parameter_type_kind = $this->generator->getTypeDescriber()->getKindForType($parameter->getDeclaringClass()->getName(),
                    $docParameter !== null
                        ? $docParameter->getType()
                        : ($parameter->getType() !== null ? (string)$parameter->getType() : null));

                if ($parameterLocation === 'body') {
                    continue;
                }

                // it is body param - skip
                if ($parameterLocation === null
                    && $parameter_type_kind !== null
                    && $parameter_type_kind !== TypeDescriber::PRIMITIVE_TYPE)
                {
                    // another extractors
                    if (!$treatExtractedArgumentsAsBody && !empty($this->argumentExtractors) && !$parameter->getType()->isBuiltin()) {
                        $argument_type = $parameter->getType()->getName();
                        foreach ($this->argumentExtractors as $argumentExtractorType => &$argumentExtractor) {
                            if (is_string($argumentExtractor)) {
                                $argumentExtractor = new $argumentExtractor($this->generator);
                            }

                            if (is_subclass_of($argument_type, $argumentExtractorType)) {
                                $parameters += $argumentExtractor->extract($actionReflection, $parameter, $required);
                            }
                        }
                        continue;
                    } else {
                        continue;
                    }
                }


                $parameter_annotation = $this->generateSchemaForParameter(
                    $parameter,
                    $docParameter,
                    $parameters_formats[$parameter->getName()] ?? null,
                    $parameters_enums[$parameter->getName()] ?? null,
                    $parameters_examples[$parameter->getName()] ?? null,
                    $parameterLocation ?? 'query',
                    $parameterName,
                );

                if (isset($parameter_annotation)) {
                    $parameters[] = $parameter_annotation;
                }
            }
        }

        foreach ($locationParameters['external'] as $definition) {
            if ($definition['location'] !== 'body') {
                $parameter = $this->generateSchemaForApiParameter(
                    $actionReflection->getDeclaringClass()->getName(),
                    $definition,
                    $parameters_formats[$definition['name']] ?? null,
                    $parameters_enums[$definition['name']] ?? null,
                    $parameters_examples[$definition['name']] ?? null
                );
                if ($parameter !== null) {
                    $this->appendUniqueParameter($parameters, $parameter);
                }
            }
        }

        return $parameters;
    }

    protected function appendUniqueParameter(array &$parameters, Parameter $parameter): void
    {
        foreach ($parameters as $existing) {
            if ($existing->name === $parameter->name && $existing->in === $parameter->in) {
                $this->generator->notice(
                    'Duplicate API parameter ' . $parameter->in . ':' . $parameter->name,
                    ErrorableObject::NOTICE_ERROR
                );
                return;
            }
        }
        $parameters[] = $parameter;
    }

    protected function generateSchemaForApiParameter(
        string $declaringClass,
        array $definition,
        ?array $formats = null,
        ?array $enums = null,
        ?array $examples = null
    ): ?Parameter {
        $nullable = false;
        $schema = $this->generator->getTypeDescriber()->generateSchemaForType(
            $declaringClass,
            $definition['type'],
            null,
            $nullable
        );
        if ($schema === null) {
            $this->generator->notice(
                'Schema for API parameter ' . $definition['name'] . ' is invalid',
                ErrorableObject::NOTICE_ERROR
            );
            return null;
        }

        if ($enums !== null && $enums !== []) {
            $schema->enum = $enums;
        }
        if ($examples !== null && $examples !== []) {
            $schema->example = current($examples);
        }

        $description = $definition['description'];
        $format = $definition['format'] ?? ($formats !== null && $formats !== [] ? current($formats) : null);
        if ($format !== null) {
            if (isset($this->customFormats[$format])) {
                $schema->mergeProperties((object)$this->customFormats[$format]);
            } else {
                $schema->format = $format;
            }
        }

        $wireName = $definition['wireName'] ?: $definition['name'];
        if ($definition['location'] === 'query' && $schema->type === 'array') {
            $wireName .= '[]';
        }

        $parameter = new Parameter([
            'name' => $wireName,
            'in' => $definition['location'],
            'description' => $description,
            'schema' => $schema,
        ]);
        if ($definition['location'] === 'path' || $definition['required']) {
            $parameter->required = true;
        }

        return $parameter;
    }

    /**
     * @param ReflectionMethod $actionReflection
     * @param DocBlock|null $docBlock
     * @return RequestBody|null
     * @throws ReflectionException
     */
    public function generatePathOperationBody(
        ReflectionMethod $actionReflection,
        ?DocBlock $docBlock,
        bool $treatExtractedArgumentsAsBody,
        bool $includeLegacyComplexArguments = true
    ): ?RequestBody
    {
        $bodyRequired = false;
        $schema = $this->generateSchemaForRequestBody(
            $docBlock,
            $actionReflection,
            $treatExtractedArgumentsAsBody,
            $includeLegacyComplexArguments,
            $bodyRequired
        );

        if ($schema === null) {
            return null;
        }

        return new RequestBody([
            'required' => $bodyRequired,
            'content' => [
                new MediaType([
                    'mediaType' => 'application/json',
                    'schema' => $schema,
                ])
            ],
        ]);
    }

    /**
     * @param string $declaringClass
     * @param string $typeSpecification
     * @param string|null $typeDescription
     * @return Schema
     * @throws ReflectionException
     */
    protected function generateSchemaForPrimitiveReturnBlock(
        string $declaringClass,
        string $typeSpecification,
        ?string $typeDescription = null
    ): string
    {
        // description
//        if (!empty($typeDescription)) {
//            $annotation_blocks[] = 'description="'.str_replace('"', '\'', $typeDescription).'"';
//        }
//        $annotation_blocks[] = $this->generateSchemaForType($typeSpecification, $declaringClass, null, $isNullable, false);
//        return '@OA\Schema(type="object", @OA\Property(property="result", '.implode(', ', $annotation_blocks).'))';
        $schema = $this->generator->getTypeDescriber()->generateSchemaForType($declaringClass, $typeSpecification, null);
        if (!empty($typeDescription))
            $schema->description = $typeDescription;
        return $schema;
    }

    /**
     * @param ReflectionParameter $pathParameter
     * @param Param|null $docBlockParameter
     * @param string|null $format
     * @param array|null $enum
     * @param array|null $examples
     * @return Parameter|null
     * @throws ReflectionException
     */
    public function generateSchemaForParameter(
        ReflectionParameter $pathParameter,
        ?Param $docBlockParameter = null,
        ?string $format = null,
        ?array $enum = null,
        ?array $examples = null,
        string $in = 'query',
        ?string $parameterName = null
    ): ?Parameter
    {
        $is_nullable_parameter = $is_required_parameter = false;

        $location = $pathParameter->getDeclaringClass()->getName().'::'.$pathParameter->getDeclaringFunction()->getName();

        $typeSpecification = $docBlockParameter !== null
            ? (string)$docBlockParameter->getType()
            : ($pathParameter->getType() !== null ? (string)$pathParameter->getType() : null);
        if ($typeSpecification === null || $typeSpecification === '') {
            $this->generator->notice('Param "'.$pathParameter->getName().'" of "'
                .$location
                .'" has neither a documented nor a native type', ErrorableObject::NOTICE_ERROR);
            return null;
        }

        if ($pathParameter->isOptional()) {
            $defaultValue = $pathParameter->getDefaultValue();
            $is_nullable_parameter = $defaultValue === null;
        } else
            $is_required_parameter = true;


        if ($docBlockParameter !== null && $this->getParameterDescription($docBlockParameter)) {
            $description = $this->getParameterDescription($docBlockParameter);

            if ($this->generator->getSetting(DefaultGenerator::PARSE_PARAMETERS_FORMAT_DESCRIPTION)
                && strpos($description, ' ') !== false
                && preg_match('~^\(([a-z]+)\)$~i', strstr($description, ' ', true), $possible_format_match)) {
                $possible_format = $possible_format_match[1];
            }
        }

        if (!isset($description) || empty($description)) {
            $description = ($this->commonParametersDescription[$pathParameter->getName()] ?? '');
        }

        $schema = $this->generator->getTypeDescriber()->generateSchemaForType(
            $pathParameter->getDeclaringClass()->getName(),
            $typeSpecification,
            $defaultValue ?? null,
            $is_nullable_parameter, null);

        if ($schema === null) {
            $this->generator->notice('Schema for '.$pathParameter->getName().' is invalid', DefaultGenerator::NOTICE_ERROR);
            return null;
        }

        if ($enum !== null && !empty($enum)) {
            $schema->enum = $enum;
        }

        if ($examples !== null && !empty($examples)) {
            $schema->example = current($examples);
        }

        if (isset($possible_format)) {
            if (in_array($possible_format, $this->basicFormats[$schema->type], true)) {
                $schema->format = $possible_format;
                $description = substr($description, strlen('('.$possible_format.') '));
            } else if (isset($this->customFormats[$possible_format])) {
                $schema->mergeProperties((object)$this->customFormats[$possible_format]);
                $description = substr($description, strlen('('.$possible_format.') '));
            }
        }

        $parameter = new Parameter([
            'name' => ($in === 'query' && $schema !== null && $schema->type === 'array')
                ? ($parameterName ?? $pathParameter->getName()) . '[]'
                : ($parameterName ?? $pathParameter->getName()),
            'in' => $in,
            'description' => $description,
            'schema' => $schema,
        ]);

        if ($in === 'path' || $is_required_parameter)
            $parameter->required = true;

        return $parameter;
    }

    /**
     * @param DocBlock|null $docBlock
     * @param ReflectionMethod $actionReflection
     * @param bool $treatExtractedArgumentsAsBody
     * @return null
     * @throws ReflectionException
     */
    protected function generateSchemaForRequestBody(
        ?DocBlock $docBlock,
        ReflectionMethod $actionReflection,
        bool $treatExtractedArgumentsAsBody,
        bool $includeLegacyComplexArguments,
        &$bodyRequired
    ): ?Schema
    {
        /** @var array<string, Param> $doc_block_parameters */
        $doc_block_parameters = [];

        if ($docBlock !== null) {
            /** @var Param $action_parameter */
            foreach ($docBlock->getTagsByName('param') as $action_parameter) {
                if ($action_parameter instanceof InvalidTag) {
                    $this->generator->onInvalidTag($action_parameter,
                       $actionReflection->getDeclaringClass()->getName().':'.$actionReflection->getName().'()'
                    );
                    continue;
                }

                $doc_block_parameters[$action_parameter->getVariableName()] = $action_parameter;
            }
        }

        $locationParameters = $this->resolveLocationParameters($docBlock, $actionReflection, $doc_block_parameters);

        $wholeBodyDefinitions = [];
        $bodyFieldDefinitions = [];
        $allLocationDefinitions = $locationParameters['external'];
        foreach ($locationParameters['bound'] as $definitions) {
            $allLocationDefinitions = array_merge($allLocationDefinitions, $definitions);
        }
        $hasRootRequestBody = false;
        foreach ($allLocationDefinitions as $definition) {
            if ($definition['location'] === 'body') {
                if ($definition['wholeBody']) {
                    $wholeBodyDefinitions[] = $definition;
                    $hasRootRequestBody = true;
                } else {
                    $bodyFieldDefinitions[] = $definition;
                }
            }
        }
        if ($wholeBodyDefinitions !== []) {
            if (count($wholeBodyDefinitions) > 1 || $bodyFieldDefinitions !== []) {
                $this->generator->notice(
                    '@requestBody cannot be combined with other explicit body parameters',
                    ErrorableObject::NOTICE_ERROR
                );
                return null;
            }
            $definition = $wholeBodyDefinitions[0];
            $nullable = false;
            $wholeBodySchema = $this->generator->getTypeDescriber()->generateSchemaForType(
                $actionReflection->getDeclaringClass()->getName(),
                $definition['type'],
                null,
                $nullable
            );
            if ($wholeBodySchema !== null && $definition['description'] !== '') {
                $wholeBodySchema->description = $definition['description'];
            }
            $bodyRequired = $definition['required'];
            return $wholeBodySchema;
        }

        $bodyParameters = [];
        $schema = new Schema([
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ]);
        foreach ($actionReflection->getParameters() as $parameter) {
            $docParameter = $doc_block_parameters[$parameter->getName()] ?? null;
            if (isset($locationParameters['bound'][$parameter->getName()])) {
                foreach ($locationParameters['bound'][$parameter->getName()] as $definition) {
                    if ($definition['location'] === 'body') {
                        $bodyParameters[] = [
                            'parameter' => $parameter,
                            'doc' => $docParameter,
                            'explicit' => true,
                            'apiParameter' => $definition,
                        ];
                    }
                }
                continue;
            }

            $explicitLocation = $this->getExplicitParameterLocation($docParameter);
            if ($explicitLocation !== null) {
                if ($explicitLocation === 'body') {
                    $bodyParameters[] = ['parameter' => $parameter, 'doc' => $docParameter, 'explicit' => true];
                }
                continue;
            }

            if ($hasRootRequestBody) {
                continue;
            }

            if (!$includeLegacyComplexArguments) {
                continue;
            }

            $typeSpecification = $docParameter !== null
                ? (string)$docParameter->getType()
                : ($parameter->getType() !== null ? (string)$parameter->getType() : null);
            $kind = $typeSpecification !== null
                ? $this->generator->getTypeDescriber()->getKindForType($parameter->getDeclaringClass()->getName(), $typeSpecification)
                : null;
            if (
                $kind === TypeDescriber::PRIMITIVE_TYPE
                && $treatExtractedArgumentsAsBody
                && !empty($this->argumentExtractors)
                && $parameter->getType() instanceof ReflectionNamedType
                && !$parameter->getType()->isBuiltin()
            ) {
                $argumentType = $parameter->getType()->getName();
                foreach ($this->argumentExtractors as $argumentExtractorType => &$argumentExtractor) {
                    if (is_string($argumentExtractor)) {
                        $argumentExtractor = new $argumentExtractor($this->generator);
                    }
                    if (is_subclass_of($argumentType, $argumentExtractorType)) {
                        $schema->properties += $argumentExtractor->extract($actionReflection, $parameter, $schema->required);
                    }
                }
            }
            if ($kind !== null && $kind !== TypeDescriber::PRIMITIVE_TYPE) {
                $bodyParameters[] = ['parameter' => $parameter, 'doc' => $docParameter, 'explicit' => false];
            }
        }

        foreach ($locationParameters['external'] as $definition) {
            if ($definition['location'] === 'body') {
                $bodyParameters[] = [
                    'parameter' => null,
                    'doc' => null,
                    'explicit' => true,
                    'apiParameter' => $definition,
                ];
            }
        }

        if ($bodyParameters === [] && $schema->properties === []) {
            return null;
        }

        foreach ($bodyParameters as $bodyParameter) {
            if (isset($bodyParameter['apiParameter']) && $bodyParameter['apiParameter']['wholeBody'] && count($bodyParameters) > 1) {
                $this->generator->notice(
                    '@requestBody/legacy whole-body metadata cannot be combined with @bodyParam fields',
                    ErrorableObject::NOTICE_ERROR
                );
                return null;
            }
        }

        $singleExplicitWholeBody = count($bodyParameters) === 1
            && $bodyParameters[0]['explicit']
            && (!isset($bodyParameters[0]['apiParameter']) || $bodyParameters[0]['apiParameter']['wholeBody']);
        if ($schema->properties === [] && $singleExplicitWholeBody) {
            if (isset($bodyParameters[0]['apiParameter'])) {
                $definition = $bodyParameters[0]['apiParameter'];
                $nullable = false;
                $requestBodySchema = $this->generator->getTypeDescriber()->generateSchemaForType(
                    $actionReflection->getDeclaringClass()->getName(),
                    $definition['type'],
                    null,
                    $nullable
                );
                if ($requestBodySchema !== null && $definition['description'] !== '') {
                    $requestBodySchema->description = $definition['description'];
                }
                $bodyRequired = $definition['required'];
                return $requestBodySchema;
            }

            $bodyParameter = $bodyParameters[0]['parameter'];
            $bodyDoc = $bodyParameters[0]['doc'];
            $typeSpecification = $bodyDoc !== null
                ? (string)$bodyDoc->getType()
                : ($bodyParameter->getType() !== null ? (string)$bodyParameter->getType() : null);
            $nullable = false;
            $defaultValue = $bodyParameter->isOptional() ? $bodyParameter->getDefaultValue() : null;
            $bodyRequired = !$bodyParameter->isOptional();

            $schema = $this->generator->getTypeDescriber()->generateSchemaForType(
                $bodyParameter->getDeclaringClass()->getName(),
                $typeSpecification,
                $defaultValue,
                $nullable
            );
            $description = $this->getParameterDescription($bodyDoc);
            if ($schema !== null && $description !== null && $description !== '') {
                $schema->description = $description;
            }
            return $schema;
        }

        foreach ($bodyParameters as $bodyParameterData) {
            if (isset($bodyParameterData['apiParameter'])) {
                $definition = $bodyParameterData['apiParameter'];
                $nullable = false;
                $property = $this->generator->getTypeDescriber()->generateSchemaForType(
                    $actionReflection->getDeclaringClass()->getName(),
                    $definition['type'],
                    null,
                    $nullable,
                    Property::class
                );
                if ($property === null) {
                    continue;
                }
                $property->property = $definition['wireName'] ?: $definition['name'];
                $property->description = $definition['description'] ?: null;
                $schema->properties[] = $property;
                if ($definition['required']) {
                    $schema->required[] = $property->property;
                }
                continue;
            }

            $parameter = $bodyParameterData['parameter'];
            $isRequired = false;
            $property = $this->generateSchemaForBodyParameter(
                $parameter,
                $bodyParameterData['doc'],
                $isRequired
            );
            if ($property === null) {
                continue;
            }
            $schema->properties[] = $property;
            if ($isRequired) {
                $schema->required[] = $parameter->getName();
            }
        }

        if ($schema->properties === []) {
            return null;
        }
        if ($schema->required === []) {
            $schema->required = \OpenApi\UNDEFINED;
        } else {
            $bodyRequired = true;
        }

        return $schema;
    }

    /**
     * @param ReflectionParameter $pathParameter
     * @param Param|null $docBlockParameter
     * @param bool $isRequired
     * @return Property|null
     * @throws ReflectionException
     */
    protected function generateSchemaForBodyParameter(
        ReflectionParameter $pathParameter,
        ?Param $docBlockParameter,
        &$isRequired = false
    ): ?Property
    {
        $is_nullable_parameter = $is_required_parameter = false;

        $location = $pathParameter->getDeclaringClass()->getName().'::'.$pathParameter->getDeclaringFunction()->getName();

        $typeSpecification = $docBlockParameter !== null
            ? (string)$docBlockParameter->getType()
            : ($pathParameter->getType() !== null ? (string)$pathParameter->getType() : null);
        if ($typeSpecification === null || $typeSpecification === '') {
            $this->generator->notice('Body param "'.$pathParameter->getName().'" of "'
                .$location
                .'" has neither a documented nor a native type', ErrorableObject::NOTICE_ERROR);
            return null;
        }

        if ($pathParameter->isOptional()) {
            $defaultValue = $pathParameter->getDefaultValue();
            $is_nullable_parameter = $defaultValue === null;
        } else
            $is_required_parameter = true;


        if ($docBlockParameter !== null && $this->getParameterDescription($docBlockParameter)) {
            $description = $this->getParameterDescription($docBlockParameter);
        }

        /** @var Property $schema */
        $schema = $this->generator->getTypeDescriber()->generateSchemaForType(
            $pathParameter->getDeclaringClass()->getName(),
            $typeSpecification,
            $defaultValue ?? null,
            $is_nullable_parameter, Property::class);
        if ($schema === null) {
            $this->generator->notice('Schema for body parameter ' . $pathParameter->getName() . ' is invalid', ErrorableObject::NOTICE_ERROR);
            return null;
        }
        $schema->property = $pathParameter->getName();
        $schema->description = $description ?? null;

        if ($is_required_parameter) {
//            $schema->required = true;
            $isRequired = true;
        }

        return $schema;
    }
}
