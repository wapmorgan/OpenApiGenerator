<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use OpenApi\Annotations\AbstractAnnotation;
use OpenApi\Annotations\Items;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\Property;
use OpenApi\Annotations\Schema;
use ReflectionException;
use wapmorgan\OpenApiGenerator\ReflectionsCollection;
use const OpenApi\Annotations\UNDEFINED;

class TypeDescriber
{
    public const PRIMITIVE_TYPE = 1;
    public const COMPLEX_ABSTRACT_TYPE = 2;
    public const COMPLEX_TYPE = 3;

    /**
     * @var DefaultGenerator
     */
    protected $generator;

    /**
     * @var array <string, array<string>>
     */
    protected $classesImports = [];

    /**
     * @var string[]
     */
    public $primitiveTypes = ['void', 'null', 'string', 'bool', 'int', 'integer', 'float', 'boolean'];

    /**
     * @var string[]
     */
    public $complexAbstractTypes = ['object', 'array', 'stdclass', 'mixed'];

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
     * @param string|null $schemaType Parameter|Schema|Property|null
     * @param null $kindOfType
     * @return Schema|Property
     * @throws ReflectionException
     */
    public function generateSchemaForType(
        string $declaringClass,
        ?string $typeSpecification,
        $defaultValue,
        &$isNullableType = false,
        ?string $schemaType = null,
        &$kindOfType = null
    ): ?Schema
    {
        $is_iterable_type = false;

        // Does not make sense to generate empty @OA\Schema
        if ($typeSpecification === null || $typeSpecification === 'void') {
            return null;
        }

        // case when bad type - only 'null'. Treat as like 'string|null'
        if ($typeSpecification === 'null') {
            $typeSpecification = 'string|null';
        }

        if (substr($typeSpecification, 0, 1) === '?') {
            $typeSpecification = substr($typeSpecification, 1);
            $isNullableType = true;
        }

        // multiple types - generate Schema for every type
        if (strpos($typeSpecification, '|') !== false) {
            return $this->generateSchemaForFewTypes(
                $typeSpecification,
                $declaringClass,
                $defaultValue,
                $isNullableType,
                $schemaType,
                $kindOfType);
        }

        // remove brackets from iterable specification
        if (substr($typeSpecification, -2) === '[]') {
            $typeSpecification = substr($typeSpecification, 0, -2);
            $is_iterable_type = true;
        }

        if (in_array(strtolower($typeSpecification), $this->primitiveTypes, true)
            || in_array(strtolower($typeSpecification), ['true', 'false'], true)) {
            $kindOfType = self::PRIMITIVE_TYPE;
            $schema = $this->generateSchemaForPrimitiveType(strtolower($typeSpecification), $defaultValue, $is_iterable_type);
        } else if (in_array(strtolower($typeSpecification), $this->complexAbstractTypes, true)) {
            $kindOfType = self::COMPLEX_ABSTRACT_TYPE;
            $schema = $this->generateSchemaForComplexAbstractType(strtolower($typeSpecification), $is_iterable_type);
        } else {
            $kindOfType = self::COMPLEX_TYPE;
            $schema = $this->generateSchemaPartsForComplexType($this->resolveLocalName($declaringClass, $typeSpecification), $is_iterable_type);
        }

        $schema->nullable = $isNullableType;

        if ($schemaType !== null && $schemaType !== Schema::class) {
            if (!is_subclass_of($schemaType, Schema::class) && $schemaType !== Parameter::class)
                throw new \RuntimeException('Invalid target Schema-class: '.$schemaType);

            /** @var Schema $new_schema */
            $new_schema = new $schemaType([]);
            $this->transferOptions($schema, $new_schema);
            return $new_schema;
        }

        return $schema;
    }

    /**
     * @param string $declaringClass
     * @param string|null $typeSpecification
     * @return int|null
     */
    public function getKindForType(
        string $declaringClass,
        ?string $typeSpecification)
    : ?int
    {
        // Does not make sense to generate empty @OA\Schema
        if ($typeSpecification === null) {
            return null;
        }

        // multiple types - generate Schema for every type
        if (strpos($typeSpecification, '|') !== false) {
            $kind = null;
            foreach (explode('|', $typeSpecification) as $type_variant) {
                $kind = max($kind, $this->getKindForType($declaringClass, $type_variant));
            }
            return $kind;
        }

        // remove brackets from iterable specification
        if (substr($typeSpecification, -2) === '[]') {
            $typeSpecification = substr($typeSpecification, 0, -2);
        }

        if (in_array(strtolower($typeSpecification), $this->primitiveTypes, true)) {
            return self::PRIMITIVE_TYPE;
        }

        if (in_array(strtolower($typeSpecification), $this->complexAbstractTypes, true)) {
            return self::COMPLEX_ABSTRACT_TYPE;
        }

        return self::COMPLEX_TYPE;
    }

    /**
     * @param object $object
     * @param bool $isIterable
     * @param string|null $schemaType
     * @return Schema|null
     * @throws ReflectionException
     */
    public function generateSchemaForObject(
        object $object,
        bool $isIterable = false,
        ?string $schemaType = null
    ): ?Schema
    {
        $schema = $this->generateSchemaPartsForObject($object, $isIterable);

        if ($schemaType !== null && $schemaType !== Schema::class) {
            if (!is_subclass_of($schemaType, Schema::class) && $schemaType !== Parameter::class)
                throw new \RuntimeException('Invalid target Schema-class: '.$schemaType);

            /** @var Schema $new_schema */
            $new_schema = new $schemaType([]);
            $this->transferOptions($schema, $new_schema);
            return $new_schema;
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
        $type = $this->canonizePrimitiveType($typeSpecification, $additionalTypeProperties);

        if ($isIterable) {
            $schema = new Schema([
                                     'type' => 'array',
                                     'items' => new Items([
                                                              'type' => $type,
                                                          ] + $additionalTypeProperties)
                                 ]);

            if (is_array($defaultValue) && !empty($defaultValue)) {
                $schema->enum = $defaultValue;
            }

        } else {
            $schema = new Schema([
                                     'type' => $type,
                                 ] + $additionalTypeProperties);
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
     * @param object $object
     * @param bool $isIterable
     * @return Schema|null
     * @throws ReflectionException
     */
    protected function generateSchemaPartsForObject(
        object $object,
        bool $isIterable
    )
    {
        $schema = $this->generator->getClassDescriber()->generateSchemaForObject($object);
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
    public function canonizePrimitiveType(string $type, &$additionalProperties = []): string
    {
        $additionalProperties = [];
        switch ($type) {
            case 'float':
            case 'double':
                $additionalProperties['format'] = 'float';
                return 'number';

            case 'int':
                return 'integer';

            case 'bool':
            case 'true':
            case 'false':
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
        static $allowed_tokens = [];

        if (empty($allowed_tokens)) {
            $allowed_tokens = [
                T_STRING,
                T_NS_SEPARATOR,
                T_WHITESPACE
            ];
            if (defined('T_NAME_QUALIFIED')) {
                $allowed_tokens[] = T_NAME_QUALIFIED;
                $allowed_tokens[] = T_NAME_FULLY_QUALIFIED;
            }
        }

        $tokens = token_get_all(file_get_contents($phpFile));
        $imports = [];

        $t = count($tokens);
        for ($i = 0; $i < $t; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_USE) {
                $import = null;
                $i += 2;
                // if `use (...)` with anonumous function
                if (!is_array($tokens[$i]) && $tokens[$i] === '(') {
                    continue;
                }
                while (is_array($tokens[$i]) && in_array($tokens[$i][0], $allowed_tokens)) {
                    if ($tokens[$i][0] !== T_WHITESPACE) {
                        $import .= defined('T_NAME_FULLY_QUALIFIED') && $tokens[$i][0] === T_NAME_FULLY_QUALIFIED
                            ? ltrim('\\', $tokens[$i][1])
                            : $tokens[$i][1];
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

    /**
     * @param string $typeSpecification
     * @param bool $isIterableType
     * @return Schema
     */
    public function generateSchemaForComplexAbstractType(string $typeSpecification, bool $isIterableType): Schema
    {
        // an array
        if ($typeSpecification === 'array') {
            $schema = new Schema([
                                     'type' => 'array',
                                     'items' => new Items([
                                                              'type' => 'object',
                                                          ]),
                                 ]);
        }
        // an object
        else if (in_array($typeSpecification, ['stdclass', 'object', 'mixed'], true)) {
            $schema = new Schema([
                                     'type' => 'object',
                                 ]);
        }

        if ($isIterableType) {
            $schema = new Schema([
                                     'type' => 'array',
                                     'items' => $schema,
                                 ]);
        }

        return $schema;
    }

    /**
     * @param Schema $schema
     * @param AbstractAnnotation $new_schema
     */
    public function transferOptions(Schema $schema, AbstractAnnotation $new_schema): void
    {
        if ($new_schema instanceof Parameter) {
            foreach (['required', 'description', 'deprecated', 'example'] as $property) {
                if ($schema->{$property} !== UNDEFINED) {
                    $new_schema->{$property} = $schema->{$property};
                }
            }
            $new_schema->schema = $schema;
        } else {
            $new_schema->mergeProperties($schema);
        }
    }

    /**
     * @param string $typeSpecification
     * @param string $declaringClass
     * @param $defaultValue
     * @param bool $isNullableType
     * @param string|null $schemaType
     * @return mixed|Property|Schema|null
     * @throws ReflectionException
     */
    protected function generateSchemaForFewTypes(
        string $typeSpecification,
        string $declaringClass,
        $defaultValue,
        bool &$isNullableType,
        ?string $schemaType,
        &$kindOfType = null)
    {
        $type_specification_variants = explode('|', $typeSpecification);

        if (in_array('null', $type_specification_variants, true)) {
            $isNullableType = true;
            unset($type_specification_variants[array_search('null', $type_specification_variants, true)]);
        }

        if (count($type_specification_variants) === 1) {
            // if there really one type after "null" removing
            $typeSpecification = current($type_specification_variants);
            return $this->generateSchemaForType($declaringClass, current($type_specification_variants), $defaultValue, $isNullableType, $schemaType, $kindOfType);
        }

        // generate schemas for few types
        $sub_type_schemas = [];

        foreach ($type_specification_variants as $subtypeSpecification) {
            $sub_type_schemas[] = $this->generateSchemaForType(
                $declaringClass,
                $subtypeSpecification,
                $defaultValue,
                $isNullableType,
                $schemaType,
                $variantType
            );
            if ($kindOfType === null || $kindOfType < $variantType) {
                $kindOfType = $variantType;
            }
        }

        if ($schemaType !== null && $schemaType !== Schema::class) {
            if (!is_subclass_of($schemaType, Schema::class) && $schemaType !== Parameter::class)
                throw new \RuntimeException('Invalid target Schema-class: ' . $schemaType);
            switch ($schemaType) {
//                        case Property::class:
//                            return new Property([
//                                'schema' => new Schema([
//                                    'oneOf' => $sub_type_schemas,
//                                ]),
//                            ]);
                default:
                    return new $schemaType([
                                               'oneOf' => $sub_type_schemas,
                                           ]);
            }
        } else {
            return new Schema([
                                  'oneOf' => $sub_type_schemas,
                              ]);
        }
    }
}
