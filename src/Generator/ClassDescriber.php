<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use OpenApi\Annotations\Property as PropertyAnnotation;
use OpenApi\Annotations\Schema;
use phpDocumentor\Reflection\DocBlock\Tags\Property as PropertyDocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use ReflectionProperty;
use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\ReflectionsCollection;

class ClassDescriber
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
     * @param $class
     * @return Schema
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function generateSchemaForClass($class)
    {
        $schema = new Schema([
            'type' => 'object',
        ]);

        $properties = [];
        $required_fields = [];
        $objectReflection = ReflectionsCollection::getClass($class);

        if ($objectReflection === false) {
            $this->generator->error(sprintf('Class "%s" could not be found', $class));
            return null;
        }

        // virtual fields
        if (($doc_text = $objectReflection->getDocComment()) !== false) {
            $doc = $this->generator->getDocBlockFactory()->create($doc_text);
            if ($doc->hasTag('property')) {
                /** @var PropertyDocBlock $object_field */
                foreach ($doc->getTagsByName('property') as $object_field) {
                    $properties[$object_field->getVariableName()] = $this->generateAnnotationForObjectVirtualProperty($object_field, $class, $is_nullable_property);
                    if (!$is_nullable_property) {
                        $required_fields[] = $object_field->getVariableName();
                    }
                }
            }
            unset($doc);
        }

        // explicit fields
        foreach ($objectReflection->getProperties(ReflectionProperty::IS_PUBLIC) as $propertyReflection) {
            $properties[$propertyReflection->getName()] = $this->generateAnnotationForObjectProperty($propertyReflection);
            $required_fields[] = $propertyReflection->getName();
        }

        if (!empty($required_fields)) {
            $schema->required = $required_fields;
        }

        return $schema;
    }

    /**
     * @param PropertyDocBlock $propertyTag
     * @param string $declaringClass
     * @param bool $isNullable
     * @return PropertyAnnotation
     * @throws \ReflectionException
     */
    protected function generateAnnotationForObjectVirtualProperty(
        PropertyDocBlock $propertyTag,
        string $declaringClass,
        &$isNullable = false
    ): PropertyAnnotation
    {
        /** @var PropertyAnnotation $property */
        $property = $this->generator->getTypeDescriber()->generateSchemaForType(
            $propertyTag->getType(),
            $declaringClass,
            null,
            $isNullable,
            true);

        $property->property = $propertyTag->getVariableName();

        // description
        if ($propertyTag->getDescription() !== null) {
            $property->description = $propertyTag->getDescription();
        }

        return $property;
    }

    /**
     * @param \ReflectionProperty $propertyReflection
     * @return PropertyAnnotation
     * @throws \ReflectionException
     */
    protected function generateAnnotationForObjectProperty(ReflectionProperty $propertyReflection): PropertyAnnotation
    {
        // type
        if (isset($doc_block) && $doc_block->getType() !== null) {
            /** @var PropertyAnnotation $property */
            $property = $this->generator->getTypeDescriber()->generateSchemaForType(
                $doc_block->getType(),
                $propertyReflection->getDeclaringClass()->getName(),
                null,
                $isNullable,
                true);
        } else {
            $property = new PropertyAnnotation([]);
        }

        $doc_comment = $propertyReflection->getDocComment();
        if ($doc_comment === false) {
            $this->generator->notice('Property "'.$propertyReflection->getName().'" of "'
                .$propertyReflection->getDeclaringClass()->getName().'" has no doc-block at all',
                ErrorableObject::NOTICE_WARNING);
        } else {
            $doc = $this->generator->getDocBlockFactory()->create($doc_comment);
            if ($doc->hasTag('var')) {
                /** @var Var_ $doc_block */
                $doc_block = $doc->getTagsByName('var')[0];
            } else {
                $this->generator->notice('Property "' . $propertyReflection->getName() . '" of "'
                    . $propertyReflection->getDeclaringClass()->getName() . '" has no @var tag',
                    ErrorableObject::NOTICE_WARNING);
                unset($doc);
            }
        }


        $property->property = $propertyReflection->getName();

        // description
        if (isset($doc_block)) {
            $property->description = $doc_block->getDescription();
        }

        return $property;
    }
}
