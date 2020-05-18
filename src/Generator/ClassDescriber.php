<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use OpenApi\Annotations\Property as PropertyAnnotation;
use OpenApi\Annotations\Schema;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\Tags\Property as PropertyDocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;
use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\ReflectionsCollection;

class ClassDescriber
{
    public const
        // Properties in PhpDoc a-la @property
        CLASS_VIRTUAL_PROPERTIES = 1,
        // Explicit class properties
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
            self::CLASS_PUBLIC_PROPERTIES => true,
            self::CLASS_VIRTUAL_PROPERTIES => [
                'property' => [
                    'enum' => true,
                    'example' => true,
                ],
            ],
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
     * @return Schema|null
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function generateSchemaForClass($class): ?Schema
    {
        $objectReflection = ReflectionsCollection::getClass($class);
        if ($objectReflection === false) {
            $this->generator->notice(sprintf('Class "%s" could not be found', $class), ErrorableObject::NOTICE_ERROR);
            return null;
        }

        $schema = $this->describeClassByReflection($objectReflection);

        if (empty($schema->properties)) {
            $this->generator->notice('Class '.$class.' has no properties after describing', ErrorableObject::NOTICE_INFO);
        }

        return $schema;
    }

    /**
     * @param object $object
     * @return Schema|null
     * @throws \ReflectionException
     */
    public function generateSchemaForObject(object $object): ?Schema
    {
        $objectReflection = new ReflectionObject($object);
        if ($objectReflection === false) {
            $this->generator->notice(sprintf('Object of class "%s" could not be found', get_class($object)), ErrorableObject::NOTICE_ERROR);
            return null;
        }

        $schema = $this->describeClassByReflection($objectReflection);

        if (empty($schema->properties)) {
            $this->generator->notice('Object of class '.get_class($object).' has no properties after describing', DefaultGenerator::NOTICE_INFO);
        }

        return $schema;
    }

    /**
     * @param ReflectionClass $reflectionClass
     * @return Schema
     * @throws \ReflectionException
     */
    protected function describeClassByReflection(ReflectionClass $reflectionClass): Schema
    {
        $schema = new Schema([
            'type' => 'object',
        ]);
        $required_fields = [];
        $properties = $this->generateAnnotationsForClassProperties($reflectionClass->getName(), $reflectionClass, $required_fields);

        if (!empty($required_fields)) {
            $schema->required = array_values(array_unique($required_fields));
        }

        if (!empty($properties)) {
            $schema->properties = [];

            foreach ($properties as $property) {
                $schema->properties[] = $property;
            }
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

        if ($property === null) {
            $this->generator->notice(ErrorableObject::NOTICE_ERROR, 'Property '.$propertyTag->getName().' is empty');
        }

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

    /**
     * @param string $class
     * @param $objectReflection
     * @param array $properties
     * @param array $requiredFields
     * @return array
     * @throws \ReflectionException
     */
    public function generateAnnotationsForClassProperties(string $class, $objectReflection, array &$requiredFields = []): array
    {
        $describing_rules = $this->getDescribingRulesForClass($class);

        $properties = [];

        // virtual fields
        if (isset($describing_rules[self::CLASS_VIRTUAL_PROPERTIES]) && ($doc_text = $objectReflection->getDocComment()) !== false) {
            $doc = $this->generator->getDocBlockFactory()->create($doc_text);

            $class_virtual_properties = $describing_rules[self::CLASS_VIRTUAL_PROPERTIES];

            foreach ($class_virtual_properties as $class_virtual_property => $class_virtual_property_config) {

                if ($doc->hasTag($class_virtual_property)) {
                    // listing all properties
                    /** @var PropertyDocBlock $object_field */
                    foreach ($doc->getTagsByName($class_virtual_property) as $object_field) {
                        if (empty((string)$object_field->getType())) {
                            $this->generator->notice('Property "' . $object_field->getVariableName() . '" of "'
                                . $objectReflection->getName() . '" has doc-block, but type is not defined. Skipping...',
                                ErrorableObject::NOTICE_WARNING);
                            continue;
                        }

                        $is_nullable_property = false;
                        $properties[$object_field->getVariableName()] = $this->generateAnnotationForObjectVirtualProperty($object_field, $class, $is_nullable_property);
                        if (!$is_nullable_property) {
                            $requiredFields[] = $object_field->getVariableName();
                        }
                    }

                    // listing all enums
                    if (isset($class_virtual_property_config['enum']) && $class_virtual_property_config['enum']) {
                        foreach ($doc->getTagsByName($class_virtual_property . 'Enum') as $object_field_enum) {
                            $enum_string = $object_field_enum->getDescription() !== null
                                ? (string)$object_field_enum->getDescription()
                                : null;
                            if (strpos($enum_string, ' ') === false) {
                                $this->generator->notice('Property enum ' . $enum_string . ' is incomplete', ErrorableObject::NOTICE_WARNING);
                                continue;
                            } else {
                                list($enum_object_field, $enum_list) = explode(' ', $enum_string, 2);
                                $enum_object_field = ltrim($enum_object_field, '$');

                                if (isset($properties[$enum_object_field])) {
                                    $properties[$enum_object_field]->enum = explode('|', $enum_list);
                                } else {
                                    $this->generator->notice('Property "'.$enum_object_field.'" of enum ' . $enum_string . ' is not defined', ErrorableObject::NOTICE_WARNING);
                                }
                            }
                        }
                    }

                    // listing all examples
                    if (isset($class_virtual_property_config['example']) && $class_virtual_property_config['example']) {
                        foreach ($doc->getTagsByName($class_virtual_property.'Example') as $object_field_example) {
                            $example_string = $object_field_example->getDescription() !== null
                                ? (string)$object_field_example->getDescription()
                                : null;
                            if (strpos($example_string, ' ') === false) {
                                $this->generator->notice('Property example ' . $example_string . ' is incomplete', ErrorableObject::NOTICE_WARNING);
                                continue;
                            } else {
                                list($example_object_field, $example_value) = explode(' ', $example_string, 2);
                                $example_object_field = ltrim($example_object_field, '$');

                                if (isset($properties[$example_object_field])) {
                                    $properties[$example_object_field]->example = $example_value;
                                } else {
                                    $this->generator->notice('Property "'.$example_object_field.'" of example ' . $example_value . ' is not defined', ErrorableObject::NOTICE_WARNING);
                                }
                            }
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
                $requiredFields[] = $propertyReflection->getName();
            }
        }

        return $properties;
    }
}
