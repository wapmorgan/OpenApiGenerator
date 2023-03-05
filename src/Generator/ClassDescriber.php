<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use OpenApi\Annotations\Property as PropertyAnnotation;
use OpenApi\Annotations\Schema;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\Tags\InvalidTag;
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
        CLASS_PUBLIC_PROPERTIES = 2,

        // Allowing to redirect describing to another type
        CLASS_REDIRECTION_PROPERTY = 10;

    /**
     * @var DefaultGenerator
     */
    protected $generator;

    /**
     * @var array List of base classes and rules for generating properties of them
     */
    protected $classesDescribingOptions = [];

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
        $reflection = ReflectionsCollection::getClass($class);
        if ($reflection === false) {
            $this->generator->notice(sprintf('Class "%s" could not be found', $class), ErrorableObject::NOTICE_ERROR);
            return null;
        }

        // get first non-empty class for describing
        $describable_class = $this->findFirstDescribableClass($reflection);
        if ($describable_class === null) {
            $this->generator->notice('Could not find any describable parent of '.$class, ErrorableObject::NOTICE_WARNING);
            return null;
        }

        $this->generator->trace('Analyzing class '.$class);

        if (($redirection = $this->getRedirection($describable_class)) !== null) {
            if (substr($redirection, 0,  1) === '$') {
                if (strpos($redirection, ' ') !== false)
                    $redirection = strstr($redirection, ' ', true);

                if (substr($redirection, -2) === '[]') {
                    $is_iterable = true;
                    $redirection = substr($redirection, 1, -2);
                } else {
                    $is_iterable = false;
                    $redirection = substr($redirection, 1);
                }

                if (!$reflection->hasProperty($redirection)) {
                    $this->generator->notice('Could not find redirection property '.$redirection.' in class '.$class, ErrorableObject::NOTICE_WARNING);
                    return null;
                }

                $properties = $reflection->getDefaultProperties();

                $this->generator->trace('Redirection from '.$describable_class->getName().' to $'.$redirection
                                        .' ('.$properties[$redirection].($is_iterable ? '[]' : null).')');

                return $this->generator->getTypeDescriber()->generateSchemaForType(
                    $describable_class->getName(),
                    $properties[$redirection].($is_iterable ? '[]' : null), null);
            } elseif (class_exists($redirection)) {
                $this->generator->trace('Redirection from '.$describable_class->getName().' to '.$redirection);
                return $this->generator->getTypeDescriber()->generateSchemaForType($describable_class->getName(), $redirection, null);
            } else {
                $this->generator->notice('Redirection tag in '.$class.' should start with $ or be a class', ErrorableObject::NOTICE_ERROR);
                return null;
            }
        }

        $schema = $this->describeClassByReflection($reflection, $describable_class);

        if (empty($schema->properties)) {
            $this->generator->notice('Class '.$class.' has no properties after describing', ErrorableObject::NOTICE_INFO);
        }

        return $schema;
    }

    /**
     * @param object $object
     * @return Schema|null
     */
    public function generateSchemaForObject(object $object): ?Schema
    {
        $reflection = new ReflectionObject($object);
        if ($reflection === false) {
            $this->generator->notice(sprintf('Object of class "%s" could not be found', get_class($object)), ErrorableObject::NOTICE_WARNING);
            return null;
        }

        // get first non-empty class for describing
        $describable_class = $this->findFirstDescribableClass($reflection);
        if ($describable_class === null) {
            $this->generator->notice('Could not find any describable parent of object of class '.get_class($object), ErrorableObject::NOTICE_WARNING);
            return null;
        }

        $this->generator->trace('Analyzing object of class '.$reflection->getParentClass()->getName());

        if (($redirection = $this->getRedirection($describable_class)) !== null) {
            if (substr($redirection, 0,  1) === '$') {
                if (strpos($redirection, ' ') !== false)
                    $redirection = strstr($redirection, ' ', true);

                if (substr($redirection, -2) === '[]') {
                    $is_iterable = true;
                    $redirection = substr($redirection, 1, -2);
                } else {
                    $is_iterable = false;
                    $redirection = substr($redirection, 1);
                }

                if (!isset($object->{$redirection})) {
                    $this->generator->notice('Could not find redirection property '.$redirection.' in object of class '.get_class($object), ErrorableObject::NOTICE_WARNING);
                    return null;
                }

                $this->generator->trace('Redirection from '.$describable_class->getName().' to $'.$redirection
                                        .' ('.var_export($object->{$redirection}, true).')');

                return $this->generator->getTypeDescriber()->generateSchemaForObject($object->{$redirection}, $is_iterable);
            } elseif (class_exists($redirection)) {
                $this->generator->trace('Redirection from '.$describable_class->getName().' to '.$redirection);
                return $this->generator->getTypeDescriber()->generateSchemaForType($describable_class->getName(), $redirection, null);
            } else {
                $this->generator->notice('Redirection tag in '.get_class($object).' should start with $', ErrorableObject::NOTICE_ERROR);
                return null;
            }
        }

        $schema = $this->describeClassByReflection($reflection, $describable_class, $object);

        if (empty($schema->properties)) {
            $this->generator->notice('Object of class '.get_class($object).' has no properties after describing', DefaultGenerator::NOTICE_INFO);
        }

        return $schema;
    }

    /**
     * @param ReflectionClass $reflectionClass
     * @param ReflectionClass $describableClass
     * @param object|null $object
     * @return Schema
     * @throws \ReflectionException
     */
    protected function describeClassByReflection(
        ReflectionClass $reflectionClass,
        ReflectionClass $describableClass,
        ?object $object = null): Schema
    {
        $schema = new Schema([
            'type' => 'object',
        ]);
        $required_fields = [];
        $properties = $this->generateAnnotationsForClassProperties(
            $reflectionClass->getName(),
            $describableClass->getName(),
            $object,
            $required_fields);

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
     * @param object|null $object
     * @return PropertyAnnotation
     * @throws \ReflectionException
     */
    protected function generateAnnotationForObjectVirtualProperty(
        PropertyDocBlock $propertyTag,
        string $declaringClass,
        &$isNullable = false,
        ?object $object = null
    ): PropertyAnnotation
    {
        // if type is a link to class/object property's value
        $property_type = trim($propertyTag->getType(), '\\');
        if (strpos($property_type, '|') !== false) {
            $property_few_types = explode('|', $property_type);
            if (count($property_few_types) > 2) {
                // it is not possible
            } else if (count($property_few_types) === 2) {
                if (in_array('null', $property_few_types)) {
                    unset($property_few_types[array_search('null', $property_few_types)]);
                    $property_type = current($property_few_types);
                    $isNullable = true;
                }
            }
        }

        if (property_exists($declaringClass, trim($property_type, '[]'))) {
            $iterable = false;
            if (substr($property_type, -2) == '[]') {
                $property_type = substr($property_type, 0, -2);
                $iterable = true;
            }

            if ($object !== null) {
                /** @var PropertyAnnotation $property */
                $property = $this->generator->getTypeDescriber()->generateSchemaForObject(
                    $object->{$property_type},
                    $iterable,
                    PropertyAnnotation::class);
            } else {
                $properties = ReflectionsCollection::getClass($declaringClass)->getDefaultProperties();
                /** @var PropertyAnnotation $property */
                $property = $this->generator->getTypeDescriber()->generateSchemaForType(
                    $declaringClass,
                    $properties[$property_type].($iterable ? '[]' : null),
                    null,
                    $isNullable,
                    PropertyAnnotation::class);
            }
        } else {
            /** @var PropertyAnnotation $property */
            $property = $this->generator->getTypeDescriber()->generateSchemaForType(
                $declaringClass,
                $property_type,
                null,
                $isNullable,
                PropertyAnnotation::class);
        }

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
        $this->generator->trace('Discovering property '.$propertyReflection->getDeclaringClass()->getName().':'.$propertyReflection->getName());
        if ($doc_comment === false) {
            $this->generator->notice('Property "'.$propertyReflection->getName().'" of "'
                .$propertyReflection->getDeclaringClass()->getName().'" has no doc-block at all',
                ErrorableObject::NOTICE_WARNING);
        } else {
            $doc = $this->generator->getDocBlockFactory()->create($doc_comment);
            if ($doc->hasTag('var')) {
                /** @var Var_ $doc_block */
                $doc_block = $doc->getTagsByName('var')[0];
                if ($doc_block instanceof InvalidTag) {
                    $this->generator->onInvalidTag($doc_block, $propertyReflection->getDeclaringClass()->getName().':'.$propertyReflection->getName());
                    unset($doc_block);
                }
            } else {
                $this->generator->notice('Property "' . $propertyReflection->getName() . '" of "'
                    . $propertyReflection->getDeclaringClass()->getName() . '" has no @var tag',
                    ErrorableObject::NOTICE_ERROR);
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
    public function setClassDescribingOptions(array $options)
    {
        $this->classesDescribingOptions = $options;
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
     * @param string $describableClass
     * @param object|null $object
     * @param array $requiredFields
     * @return array
     * @throws \ReflectionException
     */
    public function generateAnnotationsForClassProperties(
        string $class,
        string $describableClass,
        ?object $object = null,
        array &$requiredFields = []
    ): array
    {
        $describing_rules = $this->getDescribingRulesForClass($class);

        $properties = [];

        // virtual fields
        if (isset($describing_rules[self::CLASS_VIRTUAL_PROPERTIES]) && $describing_rules[self::CLASS_VIRTUAL_PROPERTIES]
            && ($doc_text = ReflectionsCollection::getClass($describableClass)->getDocComment()) !== false) {
            $doc = $this->generator->getDocBlockFactory()->create($doc_text);

            $class_virtual_properties = $describing_rules[self::CLASS_VIRTUAL_PROPERTIES];

            foreach ($class_virtual_properties as $class_virtual_property => $class_virtual_property_config) {

                if ($doc->hasTag($class_virtual_property)) {
                    // listing all properties
                    /** @var PropertyDocBlock $object_field */
                    foreach ($doc->getTagsByName($class_virtual_property) as $object_field) {
                        if ($object_field instanceof InvalidTag) {
                            $this->generator->onInvalidTag($object_field, $describableClass);
                            continue;
                        }

                        if (empty((string)$object_field->getType())) {
                            $this->generator->notice('Property "' . $object_field->getVariableName() . '" of "'
                                . $class . '" has doc-block, but type is not defined',
                                ErrorableObject::NOTICE_ERROR);
                            continue;
                        }

                        $is_nullable_property = false;
                        $properties[$object_field->getVariableName()] = $this->generateAnnotationForObjectVirtualProperty(
                            $object_field,
                            $class,
                            $is_nullable_property,
                            $object
                        );

                        if (!$is_nullable_property) {
                            $requiredFields[] = $object_field->getVariableName();
                        }
                    }

                    // listing all enums
                    if (isset($class_virtual_property_config['enum']) && $class_virtual_property_config['enum']) {
                        foreach ($doc->getTagsByName($class_virtual_property . 'Enum') as $object_field_enum) {
                            if ($object_field_enum instanceof InvalidTag) {
                                $this->generator->onInvalidTag($object_field_enum, $describableClass);
                                continue;
                            }

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
                            if ($object_field_example instanceof InvalidTag) {
                                $this->generator->onInvalidTag($object_field_example, $describableClass);
                                continue;
                            }

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
        if (isset($describing_rules[self::CLASS_PUBLIC_PROPERTIES]) && $describing_rules[self::CLASS_PUBLIC_PROPERTIES]) {
            foreach (ReflectionsCollection::getClass($class)->getProperties(ReflectionProperty::IS_PUBLIC) as $propertyReflection) {
                $properties[$propertyReflection->getName()] = $this->generateAnnotationForObjectProperty($propertyReflection);
                $requiredFields[] = $propertyReflection->getName();
            }
        }

        return $properties;
    }

    /**
     * @param string $class
     * @return bool|mixed
     */
    protected function getRedirectionPropertyForClass(string $class)
    {
        $rules = $this->getDescribingRulesForClass($class);
        return $rules[self::CLASS_REDIRECTION_PROPERTY] ?? false;
    }

    /**
     * @param ReflectionClass $reflection
     * @return false|ReflectionClass
     */
    protected function findFirstDescribableClass(ReflectionClass $reflection)
    {
        return $reflection;
//        while ($reflection instanceof ReflectionClass) {
//            if ($reflection->getDocComment() !== false) {
//                return $reflection;
//            }
//
//            $reflection = $reflection->getParentClass();
//        }
//
//        return null;
    }

    /**
     * @param ReflectionClass $reflection
     * @return string|null
     */
    protected function getRedirection(ReflectionClass $reflection)
    {
        // check for redirection tag in Marshaller
        $schema_tag = $this->getRedirectionPropertyForClass($reflection->getName());

        if ($schema_tag === false)
            return null;

        $phpdoc = $reflection->getDocComment();
        if ($phpdoc === false)
            return null;

        $doc = $this->generator->getDocBlockFactory()->create($phpdoc);
        if (!$doc->hasTag($schema_tag))
            return null;

        $tags = $doc->getTagsByName($schema_tag);
        if (count($tags) > 1) {
            $this->generator->notice(sprintf('Using first redirection tag for "%s"', $reflection->getName()), ErrorableObject::NOTICE_WARNING);
        }

        $tag = current($tags);
        if ($tag instanceof InvalidTag) {
            $this->generator->onInvalidTag($tag, $reflection->getName());
            return null;
        }

        return (string)$tag->getDescription();
    }
}
