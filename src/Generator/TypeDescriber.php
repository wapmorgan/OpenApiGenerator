<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use OpenApi\Annotations\Items;
use OpenApi\Annotations\Property;
use OpenApi\Annotations\Schema;
use ReflectionException;
use wapmorgan\OpenApiGenerator\ReflectionsCollection;

class TypeDescriber
{
    /**
     * @var DefaultGenerator
     */
    protected $generator;

    /**
     * @var array <string, array<string>>
     */
    protected $classesImports = [];

    /**
     * @var array
     */
    public $primitiveTypes = ['object', 'mixed', 'void', 'null', 'string', 'bool', 'int', 'integer', 'float', 'boolean'];

    /**
     * TypeDescriber constructor.
     * @param DefaultGenerator $generator
     */
    public function __construct(DefaultGenerator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * @param string $declaringClass
     * @param string|null $typeSpecification Тип (скалярный или сложный)
     * @param string|array|null $defaultValue
     * @param bool $isNullableType
     * @param bool $asProperty
     * @return Schema|Property
     * @throws ReflectionException
     */
    public function generateSchemaForType(
        string $declaringClass,
        ?string $typeSpecification,
        $defaultValue,
        &$isNullableType = false,
        $asProperty = false
    ): ?string
    {
        $schema_blocks = [];
        $is_iterable_type = false;

        // Does not make sense to generate empty @OA\Schema
        if ($typeSpecification === null) {
            return null;
        }

        // multiple types - generate Schema for every type
        if (strpos($typeSpecification, '|') !== false) {
            $type_specification_variants = explode('|', $typeSpecification);

            if (in_array('null', $type_specification_variants, true)) {
                $isNullableType = true;
                unset($type_specification_variants[array_search('null', $type_specification_variants, true)]);
            }

            if (count($type_specification_variants) === 1) {
                // if there really one type after "null" removing
                $typeSpecification = current($type_specification_variants);
            } else {
                // generate schemas for few types
                $sub_type_schemas = [];
                foreach ($type_specification_variants as $subtypeSpecification) {
                    $sub_type_schemas[] = $this->generateSchemaForType(
                        $declaringClass, $subtypeSpecification, $defaultValue, $isNullableType);
                }

                $type_schemas_content = 'oneOf={'.implode(', ', $sub_type_schemas).'}';
                return $asProperty
                    ? '@OA\\Schema(' . $type_schemas_content . ')'
                    : $type_schemas_content;
            }
        }

//        // hack
//        if ($typeSpecification === 'null') {
//            $typeSpecification = 'mixed';
//            $isNullableType = true;
//        }

        // remove brackets from specification
        if (substr($typeSpecification, -2) === '[]') {
            $typeSpecification = substr($typeSpecification, 0, -2);
            $is_iterable_type = true;
        }

//        // hack 2
//        if ($typeSpecification === 'array') {
//            $typeSpecification = 'object';
//            $is_iterable_type = true;
//        }

        $is_primitive = in_array($typeSpecification, $this->primitiveTypes, true);
        if ($is_primitive) {
            $schema = $this->generateSchemaForPrimitiveType(
                $typeSpecification,
                $defaultValue,
                $is_iterable_type);
        } else {
            $schema_blocks = array_merge($schema_blocks,
                $this->generateSchemaPartsForComplexType(
                    $this->resolveLocalName($declaringClass, $typeSpecification),
                    $is_iterable_type)
            );
        }

        $schema->nullable = $isNullableType;

        if ($asProperty) {
            $property = new Property([]);
            $property->mergeProperties($schema);
            return $property;
        }

        return $schema;
    }

    /**
     * @param string $typeSpecification
     * @param $defaultValue
     * @param bool $isIterable
     * @return Schema
     */
    public function generateSchemaForPrimitiveType(
        ?string $typeSpecification,
        $defaultValue,
        bool $isIterable): Schema
    {
        $type = $this->canonizePrimitiveType($typeSpecification);

        if ($isIterable) {
            $schema = new Schema([
                'type' => 'array',
                'items' => new Items([
                    'type' => $type,
                ])
            ]);

            if (is_array($defaultValue) && !empty($defaultValue)) {
                $schema->enum = $defaultValue;
            }

        } else {
            $schema = new Schema([
                'type' => $type,
            ]);
            if (!empty($defaultValue)) {
                $schema->default = $defaultValue;
            }
        }

        return $schema;
    }

    /**
     * @param string|null $typeSpecification
     * @param bool $isIterable
     * @return Schema
     * @throws ReflectionException
     */
    public function generateSchemaPartsForComplexType(
        ?string $typeSpecification,
        bool $isIterable
    ): Schema
    {
//        if (!is_subclass_of($typeSpecification, ActiveRecord::class))

        $schema = $this->generator->getClassDescriber()->generateSchemaForClass($typeSpecification);

        if ($isIterable) {
            $schema = new Schema([
                'type' => 'array',
                'items' => $schema,
            ]);
        }

        return $schema;
    }

    /**
     * @param string $type
     * @return string
     */
    public function canonizePrimitiveType(string $type): string
    {
        switch ($type) {
            case 'float':
            case 'int':
                return 'integer';

            case 'bool':
                return 'boolean';
        }

        return $type;
    }

    /**
     * @param $declaringClass
     * @param $typeName
     * @return string
     * @throws ReflectionException
     */
    public function resolveLocalName($declaringClass, $typeName)
    {
        $typeName = ltrim($typeName, '\\');

        // If declaring class is not scanned
        if (!isset($this->classesImports[$declaringClass])) {
            $this->addImports([$declaringClass]);
        }

        if (isset($this->classesImports[$declaringClass][$typeName])) {
            return $this->classesImports[$declaringClass][$typeName];
        }

        if (strpos($declaringClass, '\\') !== false && strpos($typeName, '\\') === false) {
            $namespace = substr($declaringClass, 0, strrpos($declaringClass, '\\'));
            return $namespace.'\\'.$typeName;
        }

        return $typeName;
    }

    /**
     * @param array $addedClasses
     * @throws ReflectionException
     */
    public function addImports(array $addedClasses)
    {
        // array<string file, array<string localName, string fullName>
        $cache = [];
        foreach ($addedClasses as $addedClass) {
            $class_reflection = ReflectionsCollection::getClass($addedClass);

            if (!isset($cache[$class_reflection->getFileName()]))
                $cache[$class_reflection->getFileName()] = $this->getImportsFromFile($class_reflection->getFileName());

            $this->classesImports[$addedClass] = $cache[$class_reflection->getFileName()];
        }
    }

    /**
     * Глупый метод поиска импортов в файле, для разрешения сокращенных имён
     *
     * @param string $phpFile
     * @return array
     */
    public function getImportsFromFile(string $phpFile): array
    {
        $tokens = token_get_all(file_get_contents($phpFile));
        $imports = [];

        $t = count($tokens);
        for ($i = 0; $i < $t; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_USE) {
                $import = null;
                $i += 2;
                while (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NS_SEPARATOR, T_WHITESPACE])) {
                    if ($tokens[$i][0] !== T_WHITESPACE) {
                        $import .= $tokens[$i][1];
                    }
                    $i++;
                }
                if (is_array($tokens[$i]) && $tokens[$i][0] == T_AS) {
                    $local_name = $tokens[$i + 2][1];
                } else {
                    $local_name = strpos($import, '\\') ? substr(strrchr($import, '\\'), 1) : $import;
                }
                $imports[$local_name] = $import;
            }
        }
        return $imports;
    }
}
