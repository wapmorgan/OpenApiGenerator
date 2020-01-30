<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use OpenApi\Annotations\Items;
use OpenApi\Annotations\MediaType;
use OpenApi\Annotations\Response;
use OpenApi\Annotations\Schema;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Link;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use ReflectionException;
use ReflectionMethod;
use wapmorgan\OpenApiGenerator\Scraper\DefaultPathResultWrapper;

class PathDescriber
{
    /**
     * @var DefaultGenerator
     */
    protected $generator;

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
     * @return string
     */
    public function generateAnnotationForPathDescription(DocBlock $docBlock): array
    {
        $description = [];

        if (!empty($docBlock->getDescription())) {
            $description[] = implode("\n", array_map(static function ($line) {
                return ltrim(rtrim($line), '*');
            },
                explode("\n", str_replace('"', '\'', $docBlock->getDescription()))
            ));
        }

        if (!empty($link_tags = $docBlock->getTagsByName('link'))) {
            if (count($link_tags) > 1) {
                /** @var Link $link_tag */
                foreach ($link_tags as $link_tag) {
                    $description[] = $link_tag->getLink() . (!empty($link_tag->getDescription()) ? $link_tag->getDescription() : null);
                }
            }
        }

        return implode("\n", $description);
    }

    /**
     * @param ReflectionMethod $actionReflection
     * @param DocBlock $docBlock
     * @param DefaultPathResultWrapper|null $pathResultWrapper
     * @return Schema|null
     * @throws ReflectionException
     */
    public function generationAnnotationForActionResponses(
        ReflectionMethod $actionReflection,
        DocBlock $docBlock,
        ?DefaultPathResultWrapper $pathResultWrapper
    ) : ?Response
    {
        // Generation @OA\Response
        $responses_schemas = [];
        $declaring_class = $actionReflection->getDeclaringClass()->getName();

        if ($pathResultWrapper !== null) {
            $result_wrapper_schema = $this->generateSchemaForPathResult($declaring_class, $pathResultWrapper->wrapperClass, null);
        }

        if ($docBlock->hasTag('return')) {
            /** @var Return_ $return_block */
            $return_block = $docBlock->getTagsByName('return')[0];
            $return_string = trim(substr($return_block->render(), 8));

            // Поддержка нескольких возвращемых типов
            foreach (explode('|', $return_string) as $return_type) {
                if ($return_type == 'null') continue;

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

        $response = new Response([
            'response' => 200,
            'description' => 'Successful response',
            'content' => [
                'application/json' => new MediaType([
                    'schema' => $result_block,
                ])
            ],
        ]);

        return $response;
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

        $normalized_return_spec = strtolower(strtolower($result_spec));

        // an array
        if ($result_spec === 'array') {
            return new Schema([
                'type' => 'array',
                'items' => new Items([
                    'type' => 'object',
                ]),
            ]);
        }

        // an object
        if (in_array($normalized_return_spec, ['stdclass', 'object'], true)) {
            return new Schema([
                'type' => 'object',
            ]);
        }

        // primitive types has simple schema
        if (in_array($normalized_return_spec, $this->generator->getTypeDescriber()->primitiveTypes, true)) {
            return $this->generateSchemaForPrimitiveReturnBlock($declaringClass, $normalized_return_spec, $result_type_description);
        }

        // сложный тип (объект)
        return $this->generateSchemaForComplexPathResult($result_spec,
            $result_type_description, $declaringClass);
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
}
