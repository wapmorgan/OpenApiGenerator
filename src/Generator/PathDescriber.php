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
use ReflectionParameter;
use wapmorgan\OpenApiGenerator\ErrorableObject;
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
    public function generatePathDescription(Operation $pathOperation, ?DocBlock $docBlock): void
    {
        $description = [];

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

            $return_string = trim(substr($return_block->render(), 8));

            // Поддержка нескольких возвращемых типов
            foreach (explode('|', $return_string) as $return_type) {
                if ($return_type === 'null'
                    || ($responses_schema = $this->generateSchemaForPathResultBlock($declaring_class, $return_type, $pathResultWrapper)) === null)
                    continue;
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
     * @return null
     * @throws ReflectionException
     */
    public function combineResponseWithWrapper(
        ?array $schemas,
        ReflectionMethod $actionReflection,
        ?PathResultWrapper $pathResultWrapper)
    {
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
                    'mediaType' => 'application/json',
                    'schema' => $result_block,
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
        $result_type_description = null;
        $result_spec = ltrim($returnBlock, '\\');
        if (strpos($result_spec, ' ') !== false) {
            list($result_spec, $result_type_description) = explode(' ', $result_spec, 2);
        }

        $schema = $this->generator->getTypeDescriber()->generateSchemaForType($declaringClass, $result_spec, null);
        if ($schema === null) {
            return null;
        }

        if (!empty($result_type_description)) {
            if ($schema->description === UNDEFINED)
                $schema->description = '';

            $schema->description .= PHP_EOL.$result_type_description;
        }
        return $schema;
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
        bool $treatExtractedArgumentsAsBody
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

        $parameters = [];
        $required = [];

        // Generation @OA\Parameter's
        // php arguments
        if ($actionReflection->getNumberOfParameters() > 0) {
            foreach ($actionReflection->getParameters() as $parameter) {
                $parameter_type_kind = $this->generator->getTypeDescriber()->getKindForType($parameter->getDeclaringClass()->getName(),
                    isset($doc_block_parameters[$parameter->getName()])
                        ? $doc_block_parameters[$parameter->getName()]->getType()
                        : null);

                // it is body param - skip
                if ($parameter_type_kind !== null && $parameter_type_kind !== TypeDescriber::PRIMITIVE_TYPE)
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
                    $doc_block_parameters[$parameter->getName()] ?? null,
                    $parameters_formats[$parameter->getName()] ?? null,
                    $parameters_enums[$parameter->getName()] ?? null,
                    $parameters_examples[$parameter->getName()] ?? null,
                );

                if (isset($parameter_annotation)) {
                    $parameters[] = $parameter_annotation;
                }
            }
        }

        return $parameters;
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
        bool $treatExtractedArgumentsAsBody
    ): ?RequestBody
    {
        $schema = $this->generateSchemaForRequestBody($docBlock, $actionReflection, $treatExtractedArgumentsAsBody);

        if ($schema === null) {
            return null;
        }

        return new RequestBody([
            // request body is not required if body schema has no required fields
            'required' => !($schema->required === Generator::UNDEFINED),
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
        ?array $examples = null
    ): ?Parameter
    {
        $is_nullable_parameter = $is_required_parameter = false;

        $location = $pathParameter->getDeclaringClass()->getName().'::'.$pathParameter->getDeclaringFunction()->getName();

        if ($docBlockParameter === null) {
            $this->generator->notice('Param "'.$pathParameter->getName().'" of "' . $location
                                     .'" has no doc-block at all', ErrorableObject::NOTICE_WARNING);
            return null;
        }

        if (empty((string)$docBlockParameter->getType())) {
            $this->generator->notice('Param "'.$pathParameter->getName().'" of "'
                .$location
                .'" has doc-block, but type is not defined', ErrorableObject::NOTICE_ERROR);
            return null;
        }

        if ($pathParameter->isOptional()) {
            if (($default_value_constant = $pathParameter->getDefaultValueConstantName()) === null)
                $is_nullable_parameter = true;
            else {
                // if looks like static::* or self::*
                if (preg_match('~^(self|static)\:\:([a-zA-Z_0-9]+)$~', $default_value_constant, $default_value_constant_parts)) {
                    $defaultValue = constant($pathParameter->getDeclaringClass()->getName().'::'.$default_value_constant_parts[2]);
                } else if (defined($default_value_constant)) {
                    $defaultValue = constant($default_value_constant);
                } else {
                    $this->generator->notice('Param "'.$pathParameter->getName().'" of "'
                        .$pathParameter->getDeclaringClass()->getName().'::'.$pathParameter->getDeclaringFunction()->getName()
                        .'" has unexpected default value: "'.$default_value_constant.'"', ErrorableObject::NOTICE_INFO);
                }

            }
        } else
            $is_required_parameter = true;


        if ($docBlockParameter !== null && $docBlockParameter->getDescription()) {
            $description = (string)$docBlockParameter->getDescription();

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
            $docBlockParameter !== null ? $docBlockParameter->getType() : null,
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
            'name' => $pathParameter->getName(),
            'in' => 'query',
            'description' => $description,
            'schema' => $schema,
        ]);

        if ($is_required_parameter && !$is_nullable_parameter)
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
        bool $treatExtractedArgumentsAsBody
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

        $schema = new Schema([
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ]);

        // Generation @OA\Parameter's
        if ($actionReflection->getNumberOfParameters() > 0) {
            foreach ($actionReflection->getParameters() as $parameter) {
                $parameter_type_kind = $this->generator->getTypeDescriber()->getKindForType($parameter->getDeclaringClass()->getName(),
                    isset($doc_block_parameters[$parameter->getName()])
                        ? $doc_block_parameters[$parameter->getName()]->getType()
                        : null);

                // it is request param - skip
                if ($parameter_type_kind === null)
                    continue;

                if ($parameter_type_kind === TypeDescriber::PRIMITIVE_TYPE) {
                    // another extractors
                    if ($treatExtractedArgumentsAsBody && !empty($this->argumentExtractors) && !$parameter->getType()->isBuiltin()) {
                        $argument_type = $parameter->getType()->getName();
                        foreach ($this->argumentExtractors as $argumentExtractorType => &$argumentExtractor) {
                            if (is_string($argumentExtractor)) {
                                $argumentExtractor = new $argumentExtractor($this->generator);
                            }

                            if (is_subclass_of($argument_type, $argumentExtractorType)) {
//                                $schema->properties[$parameter->getName()] = $this->generateSchemaForBodyParameter(
//                                    $parameter,
//                                    $doc_block_parameters[$parameter->getName()] ?? null,
//                                    $isRequired
//                                );
                                $schema->properties += $argumentExtractor->extract($actionReflection, $parameter, $schema->required);
                            }
                        }
                    }
                    continue;
                }

                $schema->properties[$parameter->getName()] = $this->generateSchemaForBodyParameter(
                    $parameter,
                    $doc_block_parameters[$parameter->getName()] ?? null,
                    $isRequired
                );

                if ($isRequired) {
                    $schema->required[] = $parameter->getName();
                }
            }
        }

        if (empty($schema->properties))
            return null;

        if (empty($schema->required))
            $schema->required = Generator::UNDEFINED;

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

        if ($docBlockParameter === null) {
            $this->generator->notice('Body param "'.$pathParameter->getName().'" of "'
                . $location
                .'" has no doc-block at all', ErrorableObject::NOTICE_WARNING);
            return null;
        }

        if (empty((string)$docBlockParameter->getType())) {
            $this->generator->notice('Body param "'.$pathParameter->getName().'" of "'
                .$location
                .'" has doc-block, but type is not defined', ErrorableObject::NOTICE_ERROR);
            return null;
        }

        if ($pathParameter->isOptional()) {
            if (($default_value_constant = $pathParameter->getDefaultValueConstantName()) === null)
                $is_nullable_parameter = true;
            else {
                // if looks like static::* or self::*
                if (preg_match('~^(self|static)\:\:([a-zA-Z_0-9]+)$~', $default_value_constant, $default_value_constant_parts)) {
                    $defaultValue = constant($pathParameter->getDeclaringClass()->getName().'::'.$default_value_constant_parts[2]);
                } else if (defined($default_value_constant)) {
                    $defaultValue = constant($default_value_constant);
                } else {
                    $this->generator->notice('Param "'.$pathParameter->getName().'" of "'
                        .$pathParameter->getDeclaringClass()->getName().'::'.$pathParameter->getDeclaringFunction()->getName()
                        .'" has unexpected default value: "'.$default_value_constant.'"', ErrorableObject::NOTICE_INFO);
                }

            }
        } else
            $is_required_parameter = true;


        if ($docBlockParameter !== null && $docBlockParameter->getDescription()) {
            $description = (string)$docBlockParameter->getDescription();
        }

        /** @var Property $schema */
        $schema = $this->generator->getTypeDescriber()->generateSchemaForType(
            $pathParameter->getDeclaringClass()->getName(),
            $docBlockParameter !== null ? $docBlockParameter->getType() : null,
            $defaultValue ?? null,
            $is_nullable_parameter, Property::class);
        $schema->property = $pathParameter->getName();
        $schema->description = $description ?? null;

        if ($is_required_parameter && !$is_nullable_parameter) {
//            $schema->required = true;
            $isRequired = true;
        }

        return $schema;
    }
}
