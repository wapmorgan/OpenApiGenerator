<?php
namespace wapmorgan\OpenApiGenerator\Generator;

use OpenApi\Annotations\AbstractAnnotation;
use OpenApi\Annotations\AdditionalProperties;
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
        if ($this->containsTopLevelDelimiter($typeSpecification, '|')) {
            return $this->generateSchemaForFewTypes(
                $typeSpecification,
                $declaringClass,
                $defaultValue,
                $isNullableType,
                $schemaType,
                $kindOfType);
        }

        $arrayShape = $this->parseArrayShape($typeSpecification);
        if ($arrayShape !== null) {
            $kindOfType = self::COMPLEX_ABSTRACT_TYPE;
            $schema = $this->generateSchemaForArrayShape($declaringClass, $arrayShape, $schemaType);
            $schema->nullable = $isNullableType;
            return $schema;
        }

        $genericArray = $this->parseGenericArray($typeSpecification);
        if ($genericArray !== null) {
            $kindOfType = self::COMPLEX_ABSTRACT_TYPE;
            $schema = $this->generateSchemaForGenericArray($declaringClass, $genericArray, $defaultValue, $schemaType);
            $schema->nullable = $isNullableType;
            return $schema;
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

        if ($schema === null) {
            return null;
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

        if (substr($typeSpecification, 0, 1) === '?') {
            $typeSpecification = substr($typeSpecification, 1);
        }

        // multiple types - generate Schema for every type
        if ($this->containsTopLevelDelimiter($typeSpecification, '|')) {
            $kind = null;
            foreach ($this->splitTopLevel($typeSpecification, '|') as $type_variant) {
                $variantKind = $this->getKindForType($declaringClass, $type_variant);
                if ($variantKind !== null) {
                    $kind = $kind === null ? $variantKind : max($kind, $variantKind);
                }
            }
            return $kind;
        }

        if ($this->parseArrayShape($typeSpecification) !== null || $this->parseGenericArray($typeSpecification) !== null) {
            return self::COMPLEX_ABSTRACT_TYPE;
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

            if ($defaultValue !== null) {
                $schema->default = $defaultValue;
            }

        } else {
            $schema = new Schema([
                                     'type' => $type,
                                 ] + $additionalTypeProperties);
            if ($defaultValue !== null) {
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
    ): ?Schema
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
     * Parse array/list shapes such as `array{name: string, age?: int}`.
     *
     * @return array|null
     */
    protected function parseArrayShape(string $typeSpecification): ?array
    {
        if (!preg_match('/^(?:array|list)\s*\{(.*)\}$/s', trim($typeSpecification), $matches)) {
            return null;
        }

        $properties = [];
        $additionalType = null;
        foreach ($this->splitTopLevel($matches[1], ',') as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (strpos($part, '...') === 0) {
                $additionalType = trim(substr($part, 3));
                if (substr($additionalType, 0, 1) === '<' && substr($additionalType, -1) === '>') {
                    $genericTypes = $this->splitTopLevel(substr($additionalType, 1, -1), ',');
                    $additionalType = end($genericTypes);
                }
                continue;
            }

            $colonPosition = $this->findTopLevelCharacter($part, ':');
            if ($colonPosition === null) {
                return null;
            }

            $propertyName = trim(substr($part, 0, $colonPosition));
            $optional = substr($propertyName, -1) === '?';
            if ($optional) {
                $propertyName = substr($propertyName, 0, -1);
            }
            $propertyName = trim($propertyName, " \t\n\r\0\x0B'\"");
            if ($propertyName === '') {
                return null;
            }

            $properties[] = [
                'name' => $propertyName,
                'type' => trim(substr($part, $colonPosition + 1)),
                'optional' => $optional,
            ];
        }

        return ['properties' => $properties, 'additionalType' => $additionalType];
    }

    /**
     * Parse array/list generic declarations such as `array<string, Foo>` and `list<Foo>`.
     *
     * @return array|null
     */
    protected function parseGenericArray(string $typeSpecification): ?array
    {
        if (!preg_match('/^(array|list)\s*<(.+)>$/s', trim($typeSpecification), $matches)) {
            return null;
        }

        $types = $this->splitTopLevel($matches[2], ',');
        if ($matches[1] === 'list' && count($types) === 1) {
            return ['kind' => 'list', 'valueType' => trim($types[0])];
        }
        if (count($types) === 1) {
            return ['kind' => 'list', 'valueType' => trim($types[0])];
        }
        if (count($types) === 2) {
            return ['kind' => 'map', 'keyType' => trim($types[0]), 'valueType' => trim($types[1])];
        }

        return null;
    }

    protected function generateSchemaForArrayShape(string $declaringClass, array $shape, ?string $schemaType): Schema
    {
        $properties = [];
        $required = [];
        foreach ($shape['properties'] as $property) {
            $nullable = false;
            $kind = null;
            $schema = $this->generateSchemaForType(
                $declaringClass,
                $property['type'],
                null,
                $nullable,
                Property::class,
                $kind
            );
            if ($schema === null) {
                continue;
            }
            $schema->property = $property['name'];
            $properties[] = $schema;
            if (!$property['optional']) {
                $required[] = $property['name'];
            }
        }

        $schema = new Schema([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required ?: \OpenApi\Generator::UNDEFINED,
        ]);

        if ($shape['additionalType'] !== null && $shape['additionalType'] !== '') {
            $nullable = false;
            $kind = null;
            $additionalSchema = $this->generateSchemaForType(
                $declaringClass,
                $shape['additionalType'],
                null,
                $nullable,
                AdditionalProperties::class,
                $kind
            );
            if ($additionalSchema !== null) {
                $schema->additionalProperties = $additionalSchema;
            }
        }

        return $this->convertSchemaType($schema, $schemaType);
    }

    protected function generateSchemaForGenericArray(string $declaringClass, array $generic, $defaultValue, ?string $schemaType): Schema
    {
        $nullable = false;
        $kind = null;
        $itemSchema = $this->generateSchemaForType($declaringClass, $generic['valueType'], null, $nullable);
        if ($itemSchema === null) {
            $itemSchema = new Schema(['type' => 'object']);
        }

        if ($generic['kind'] === 'map') {
            $additionalProperties = $this->convertSchemaType($itemSchema, AdditionalProperties::class);
            $schema = new Schema([
                'type' => 'object',
                'additionalProperties' => $additionalProperties,
            ]);
        } else {
            $items = $this->convertSchemaType($itemSchema, Items::class);
            $schema = new Schema([
                'type' => 'array',
                'items' => $items,
            ]);
            if ($defaultValue !== null) {
                $schema->default = $defaultValue;
            }
        }

        return $this->convertSchemaType($schema, $schemaType);
    }

    protected function convertSchemaType(Schema $schema, ?string $schemaType): Schema
    {
        if ($schemaType === null || $schemaType === Schema::class) {
            return $schema;
        }
        if (!is_subclass_of($schemaType, Schema::class) && $schemaType !== Parameter::class) {
            throw new \RuntimeException('Invalid target Schema-class: ' . $schemaType);
        }

        $converted = new $schemaType([]);
        $this->transferOptions($schema, $converted);
        return $converted;
    }

    protected function containsTopLevelDelimiter(string $value, string $delimiter): bool
    {
        return count($this->splitTopLevel($value, $delimiter)) > 1;
    }

    /**
     * Split a union type without splitting unions nested inside generic or shape declarations.
     *
     * @return string[]
     */
    public function splitTypeVariants(string $typeSpecification): array
    {
        return array_map('trim', $this->splitTopLevel($typeSpecification, '|'));
    }

    /** @return string[] */
    protected function splitTopLevel(string $value, string $delimiter): array
    {
        $parts = [];
        $start = 0;
        $angle = $brace = $paren = $bracket = 0;
        $quote = null;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $character = $value[$i];
            if ($quote !== null) {
                if ($character === $quote && ($i === 0 || $value[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '\'' || $character === '"') {
                $quote = $character;
                continue;
            }
            switch ($character) {
                case '<': $angle++; break;
                case '>': $angle = max(0, $angle - 1); break;
                case '{': $brace++; break;
                case '}': $brace = max(0, $brace - 1); break;
                case '(': $paren++; break;
                case ')': $paren = max(0, $paren - 1); break;
                case '[': $bracket++; break;
                case ']': $bracket = max(0, $bracket - 1); break;
                default:
                    if ($character === $delimiter && $angle === 0 && $brace === 0 && $paren === 0 && $bracket === 0) {
                        $parts[] = substr($value, $start, $i - $start);
                        $start = $i + 1;
                    }
            }
        }
        $parts[] = substr($value, $start);
        return $parts;
    }

    protected function findTopLevelCharacter(string $value, string $characterToFind): ?int
    {
        $parts = $this->splitTopLevel($value, $characterToFind);
        if (count($parts) < 2) {
            return null;
        }
        return strlen($parts[0]);
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
        $type_specification_variants = $this->splitTopLevel($typeSpecification, '|');

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
            $variantType = null;
            $subTypeSchema = $this->generateSchemaForType(
                $declaringClass,
                $subtypeSpecification,
                $defaultValue,
                $isNullableType,
                $schemaType,
                $variantType
            );
            if ($subTypeSchema !== null) {
                $sub_type_schemas[] = $subTypeSchema;
            }
            if ($variantType !== null && ($kindOfType === null || $kindOfType < $variantType)) {
                $kindOfType = $variantType;
            }
        }

        if ($sub_type_schemas === []) {
            return null;
        }
        if (count($sub_type_schemas) === 1) {
            return current($sub_type_schemas);
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
