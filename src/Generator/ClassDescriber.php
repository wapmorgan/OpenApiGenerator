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
    public const
        CLASS_VIRTUAL_PROPERTIES = 1,
        CLASS_PUBLIC_PROPERTIES = 2;

    /**
     * @var DefaultGenerator
     */
    protected $generator;

    /**
     * @var array List of base classes and rules for generating properties of them
     */
    protected $classesDescribingOptions = [
        null => [
            self::CLASS_PUBLIC_PROPERTIES,
            self::CLASS_VIRTUAL_PROPERTIES => ['property']
        ],
    ];

    /**
     * TypeDescriber constructor.
     * @param DefaultGenerator $generator
     */
    public function __construct(DefaultGenerator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * @param string $class
     * @return Schema
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function generateSchemaForClass($class): ?Schema
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

        $describing_rules = $this->getDescribingRulesForClass($class);

        // virtual fields
        if (isset($describing_rules[self::CLASS_VIRTUAL_PROPERTIES]) && ($doc_text = $objectReflection->getDocComment()) !== false) {
            $doc = $this->generator->getDocBlockFactory()->create($doc_text);

            $class_virtual_properties = $describing_rules[self::CLASS_VIRTUAL_PROPERTIES];
            if (is_string($class_virtual_properties)) $class_virtual_properties = [$class_virtual_properties];

            foreach ($class_virtual_properties as $class_virtual_property) {
                if ($doc->hasTag($class_virtual_property)) {
                    /** @var PropertyDocBlock $object_field */
                    foreach ($doc->getTagsByName($class_virtual_property) as $object_field) {
                        if (empty((string)$object_field->getType())) {
                            $this->generator->notice('Property "'.$object_field->getVariableName().'" of "'
                                .$objectReflection->getName().'" has doc-block, but type is not defined. Skipping...',
                                ErrorableObject::NOTICE_WARNING);
                            continue;
                        }

                        $is_nullable_property = false;
                        $properties[$object_field->getVariableName()] = $this->generateAnnotationForObjectVirtualProperty($object_field, $class, $is_nullable_property);
                        if (!$is_nullable_property) {
                            $required_fields[] = $object_field->getVariableName();
                        }
                    }
                }
            }
            unset($doc);
        }

        // explicit fields
        if (in_array(self::CLASS_PUBLIC_PROPERTIES, $describing_rules, true)) {
            foreach ($objectReflection->getProperties(ReflectionProperty::IS_PUBLIC) as $propertyReflection) {
                $properties[$propertyReflection->getName()] = $this->generateAnnotationForObjectProperty($propertyReflection);
                $required_fields[] = $propertyReflection->getName();
            }
        }

        if (!empty($required_fields)) {
            $schema->required = array_values(array_unique($required_fields));
        }

        if (!empty($properties)) {
            $schema->properties = [];

            foreach ($properties as $property) {
                $schema->properties[] = $property;
            }
        } else {
            $this->generator->notice('Class '.$class.' has no properties after describing', DefaultGenerator::NOTICE_INFO);
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
            $declaringClass,
            $propertyTag->getType(),
            null,
            $isNullable,
            PropertyAnnotation::class);
        if ($property === null) {var_dump(func_get_args(), $propertyTag); }

        $property->property = $propertyTag->getVariableName();

        // description
        if ($propertyTag->getDescription() !== null) {
            $property->description = (string)$propertyTag->getDescription();
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
        $doc_comment = $propertyReflection->getDocComment();
        if ($doc_comment === false) {
            $this->generator->notice('Property "'.$propertyReflection->getName().'" of "'
                .$propertyReflection->getDeclaringClass()->getName().'" has no doc-block at all',
                ErrorableObject::NOTICE_INFO);
        } else {
            $doc = $this->generator->getDocBlockFactory()->create($doc_comment);
            if ($doc->hasTag('var')) {
                /** @var Var_ $doc_block */
                $doc_block = $doc->getTagsByName('var')[0];
            } else {
                $this->generator->notice('Property "' . $propertyReflection->getName() . '" of "'
                    . $propertyReflection->getDeclaringClass()->getName() . '" has no @var tag',
                    ErrorableObject::NOTICE_INFO);
                unset($doc);
            }
        }

        // type
        if (isset($doc_block) && $doc_block->getType() !== null) {
            $isNullable = false;
            /** @var PropertyAnnotation $property */
            $property = $this->generator->getTypeDescriber()->generateSchemaForType(
                $propertyReflection->getDeclaringClass()->getName(),
                $doc_block->getType(),
                null,
                $isNullable,
                PropertyAnnotation::class);
        } else {
            $property = new PropertyAnnotation([]);
        }


        $property->property = $propertyReflection->getName();

        // description
        if (isset($doc_block)) {
            $property->description = (string)$doc_block->getDescription();
        }

        return $property;
    }

    /**
     * @param string|null $class
     * @param array $options
     */
    public function setClassDescribingOptions(?string $class, array $options = [])
    {
        $this->classesDescribingOptions[$class] = $options;
    }

    /**
     * @param string $class
     * @return array
     */
    public function getDescribingRulesForClass(string $class): array
    {
        $rules = array_reverse($this->classesDescribingOptions);
        foreach ($rules as $ruleClass => $ruleOptions) {
            if ($ruleClass === null) continue;

            if (is_subclass_of($class, $ruleClass)) {
                return $ruleOptions;
            }
        }

        return $this->classesDescribingOptions[null];
    }
}
