<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use OpenApi\Annotations\ExternalDocumentation;
use OpenApi\Annotations\Items;
use OpenApi\Annotations\MediaType;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\Response;
use OpenApi\Annotations\Schema;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Link;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;
use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\Scraper\DefaultPathResultWrapper;
use yii\helpers\Console;
use const OpenApi\UNDEFINED;

class PathDescriber
{
    /**
     * @var DefaultGenerator
     */
    protected $generator;

    protected $commonParameters = [];

    /**
     * TypeDescriber constructor.
     * @param DefaultGenerator $generator
     */
    public function __construct(DefaultGenerator $generator)
    {
        $this->generator = $generator;
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
                    $description[] = $link_tag->getLink() . (!empty($link_tag->getDescription()) ? $link_tag->getDescription() : '');
                }
            } else {
                $pathOperation->externalDocs = new ExternalDocumentation([
                    'url' => $link_tags[0]->getLink(),
                    'description' => (!empty($link_tags[0]->getDescription()) ? (string)$link_tags[0]->getDescription() : ''),
                ]);
            }
        }

        $pathOperation->description = implode("\n", $description);
    }

    /**
     * @param ReflectionMethod $actionReflection
     * @param DocBlock|null $docBlock
     * @param DefaultPathResultWrapper|null $pathResultWrapper
     * @return Schema|null
     * @throws ReflectionException
     */
    public function generationPathMethodResponses(
        ReflectionMethod $actionReflection,
        ?DocBlock $docBlock,
        ?DefaultPathResultWrapper $pathResultWrapper
    ) : ?Response
    {
        // Generation @OA\Response
        $responses_schemas = [];
        $declaring_class = $actionReflection->getDeclaringClass()->getName();

        if ($pathResultWrapper !== null) {
            $result_wrapper_schema = $this->generateSchemaForPathResult($declaring_class, $pathResultWrapper->wrapperClass, null);
        }

        if ($docBlock !== null && $docBlock->hasTag('return')) {
            /** @var Return_ $return_block */
            $return_block = $docBlock->getTagsByName('return')[0];
            $return_string = trim(substr($return_block->render(), 8));

            // Поддержка нескольких возвращемых типов
            foreach (explode('|', $return_string) as $return_type) {
                if ($return_type === 'null') continue;

                $responses_schemas[] = $this->generateSchemaForPathResult($declaring_class, $return_type, $pathResultWrapper);
            }

            if (isset($result_wrapper_schema)) {
                foreach ($responses_schemas as $i => &$result_combination) {
                    $result_combination = new Schema([
                        'allOf' => [
                            $result_wrapper_schema,
                            $result_combination,
                        ],
                    ]);
                }
            }
        } else if (isset($result_wrapper_schema)) {
            $responses_schemas = [$result_wrapper_schema];
        } else {
            return null;
        }

        if (count($responses_schemas) === 1) {
            $result_block = current($responses_schemas);
        } else {
            $result_block = new Schema([
                'oneOf' => $responses_schemas,
            ]);
        }

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
     * @param DefaultPathResultWrapper|null $pathResultWrapper
     * @return Schema
     * @throws ReflectionException
     */
    protected function generateSchemaForPathResult(
        string $declaringClass,
        string $returnBlock,
        ?DefaultPathResultWrapper $pathResultWrapper = null
    ): Schema {
        $schema = $this->generateSchemaForReturnBlock($declaringClass, $returnBlock);
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
    ): Schema {
        $result_type_description = null;
        $result_spec = ltrim($returnBlock, '\\');
        if (strpos($result_spec, ' ') !== false) {
            list($result_spec, $result_type_description) = explode(' ', $result_spec, 2);
        }

        $schema = $this->generator->getTypeDescriber()->generateSchemaForType($declaringClass, $result_spec, null);
        if (!empty($result_type_description)) {
            if ($schema->description === UNDEFINED)
                $schema->description = '';

            $schema->description .= PHP_EOL.$result_type_description;
        }
        return $schema;
    }

    /**
     * @param ReflectionMethod $actionReflection
     * @param DocBlock|null $docBlock
     * @return Parameter[]
     * @throws ReflectionException
     */
    public function generatePathOperationParameters(ReflectionMethod $actionReflection, ?DocBlock $docBlock): array
    {
        /** @var array<string, Param> $doc_block_parameters */
        $doc_block_parameters = [];

        if ($docBlock !== null) {
            /** @var Param $action_parameter */
            foreach ($docBlock->getTagsByName('param') as $action_parameter) {
                $doc_block_parameters[$action_parameter->getVariableName()] = $action_parameter;
            }
        }

        $parameters = [];

        // Generation @OA\Parameter's
        if ($actionReflection->getNumberOfParameters() > 0) {
            foreach ($actionReflection->getParameters() as $parameter) {
                $parameter_annotation = $this->generateSchemaForParameter(
                    $parameter,
                    $doc_block_parameters[$parameter->getName()] ?? null
                );

                if ($parameter_annotation !== null) {
                    $parameters[] = $parameter_annotation;
                }
            }
        }

        return $parameters;
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
     * @return Parameter|null
     * @throws ReflectionException
     * @throws \Exception
     */
    public function generateSchemaForParameter(
        ReflectionParameter $pathParameter,
        ?Param $docBlockParameter = null
    ): ?Parameter
    {
        $is_nullable_parameter = $is_required_parameter = false;

        if ($docBlockParameter === null) {
            $this->generator->notice('Param "'.$pathParameter->getName().'" of "'
                .$pathParameter->getDeclaringClass()->getName().'::'.$pathParameter->getDeclaringFunction()->getName()
                .'" has no doc-block at all, skipping', ErrorableObject::NOTICE_ERROR);
            return null;
        }

        if (empty((string)$docBlockParameter->getType())) {
            $this->generator->error('Param "'.$pathParameter->getName().'" of "'
                .$pathParameter->getDeclaringClass()->getName().'::'.$pathParameter->getDeclaringFunction()->getName()
                .'" has doc-block, but type is not defined. Skipping...');
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

        if (!isset($description) || empty($description)) {
            $description = ($this->commonParameters[$pathParameter->getName()] ?? '');
        }

        $schema = $this->generator->getTypeDescriber()->generateSchemaForType(
            $pathParameter->getDeclaringClass()->getName(),
            $docBlockParameter !== null ? $docBlockParameter->getType() : null,
            $defaultValue ?? null,
            $is_nullable_parameter);
        ;

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
     * @param $name
     * @param $description
     * @return $this
     */
    public function setCommonParameter($name, $description)
    {
        $this->commonParameters[$name] = $description;
        return $this;
    }
}
