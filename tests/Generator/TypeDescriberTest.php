<?php

namespace wapmorgan\OpenApiGenerator\Tests\Generator;

use OpenApi\Annotations\AdditionalProperties;
use OpenApi\Annotations\Items;
use OpenApi\Annotations\PathItem;
use OpenApi\Annotations\Property;
use OpenApi\Annotations\RequestBody;
use OpenApi\Annotations\Schema;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use wapmorgan\OpenApiGenerator\Generator\DefaultGenerator;
use wapmorgan\OpenApiGenerator\Scraper\Endpoint;

class TypeDescriberTest extends TestCase
{
    private DefaultGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new TestableDefaultGenerator();
    }

    public function testGenericArrayMapDoesNotResolveAsClass(): void
    {
        $schema = $this->describe('array<string, mixed>');

        $this->assertInstanceOf(Schema::class, $schema);
        $this->assertSame('object', $schema->type);
        $this->assertInstanceOf(AdditionalProperties::class, $schema->additionalProperties);
        $this->assertSame('object', $schema->additionalProperties->type);
        $this->assertIsString(json_encode($schema));
    }

    public function testGenericListCreatesTypedItems(): void
    {
        $schema = $this->describe('list<int>');

        $this->assertSame('array', $schema->type);
        $this->assertInstanceOf(Items::class, $schema->items);
        $this->assertSame('integer', $schema->items->type);
    }

    public function testNestedGenericMapCreatesNestedArraySchema(): void
    {
        $schema = $this->describe('array<string, list<string>>');

        $this->assertSame('object', $schema->type);
        $this->assertInstanceOf(AdditionalProperties::class, $schema->additionalProperties);
        $this->assertSame('array', $schema->additionalProperties->type);
        $this->assertInstanceOf(Items::class, $schema->additionalProperties->items);
        $this->assertSame('string', $schema->additionalProperties->items->type);
    }

    public function testArrayShapeCreatesPropertiesAndRequiredList(): void
    {
        $schema = $this->describe('array{success: bool, count?: int}');

        $this->assertSame('object', $schema->type);
        $this->assertCount(2, $schema->properties);
        $this->assertInstanceOf(Property::class, $schema->properties[0]);
        $this->assertSame('success', $schema->properties[0]->property);
        $this->assertSame('boolean', $schema->properties[0]->type);
        $this->assertSame('count', $schema->properties[1]->property);
        $this->assertSame(['success'], $schema->required);
        $this->assertIsString(json_encode($schema));
    }

    public function testNestedArrayShapeAndUnionTypesAreParsed(): void
    {
        $schema = $this->describe('array{data: array{value: string|int}}');

        $this->assertSame('object', $schema->type);
        $nested = $schema->properties[0];
        $this->assertSame('data', $nested->property);
        $this->assertSame('object', $nested->type);
        $this->assertSame('value', $nested->properties[0]->property);
        $this->assertNotEmpty($nested->properties[0]->oneOf);
    }

    public function testReturnTagWithShapeIsGeneratedWithoutTruncatingType(): void
    {
        $responses = $this->generateResponsesFor('documentedShapeResponse');

        $this->assertCount(1, $responses);
        $schema = $responses[0];
        $this->assertSame('object', $schema->type);
        $this->assertSame('success', $schema->properties[0]->property);
        $this->assertSame('Response payload.', $schema->description);
    }

    public function testReturnTagWithGenericMapIsGeneratedAsObject(): void
    {
        $responses = $this->generateResponsesFor('documentedGenericMapResponse');

        $this->assertCount(1, $responses);
        $this->assertSame('object', $responses[0]->type);
        $this->assertInstanceOf(AdditionalProperties::class, $responses[0]->additionalProperties);
        $this->assertSame('integer', $responses[0]->additionalProperties->type);
    }

    public function testGenericArrayParameterIsGeneratedAsRequestObject(): void
    {
        $method = new ReflectionMethod($this, 'documentedMapParameter');
        $docBlock = $this->generator->getDocBlockFactory()->create($method->getDocComment());
        /** @var Param $parameterTag */
        $parameterTag = $docBlock->getTagsByName('param')[0];
        $isRequired = false;
        $describerMethod = new \ReflectionMethod(
            $this->generator->getPathDescriber(),
            'generateSchemaForBodyParameter'
        );
        $describerMethod->setAccessible(true);
        $schema = $describerMethod->invokeArgs(
            $this->generator->getPathDescriber(),
            [$method->getParameters()[0], $parameterTag, &$isRequired]
        );

        $this->assertInstanceOf(Property::class, $schema);
        $this->assertSame('object', $schema->type);
        $this->assertInstanceOf(AdditionalProperties::class, $schema->additionalProperties);
        $this->assertSame('integer', $schema->additionalProperties->type);
        $this->assertTrue($isRequired);
    }

    public function testParamLocationPrefixSupportsPathQueryHeaderAndCookie(): void
    {
        $method = new ReflectionMethod($this, 'documentedParameterLocations');
        $docBlock = $this->generator->getDocBlockFactory()->create($method->getDocComment());
        $parameters = $this->generator->getPathDescriber()->generatePathOperationParameters(
            $method,
            $docBlock,
            false,
            ['resource_id']
        );
        $parametersByName = [];
        foreach ($parameters as $parameter) {
            $parametersByName[$parameter->name] = $parameter;
        }

        $this->assertSame('path', $parametersByName['resource_id']->in);
        $this->assertTrue($parametersByName['resource_id']->required);
        $this->assertSame('Resource identifier.', $parametersByName['resource_id']->description);
        $this->assertSame('query', $parametersByName['filter']->in);
        $this->assertSame('object', $parametersByName['filters']->schema->type);
        $this->assertInstanceOf(AdditionalProperties::class, $parametersByName['filters']->schema->additionalProperties);
        $this->assertSame('header', $parametersByName['X-Request-Id']->in);
        $this->assertSame('cookie', $parametersByName['locale']->in);
    }

    public function testNativeNullableTypeIsUsedWhenParamTagIsMissing(): void
    {
        $method = new ReflectionMethod($this, 'nativeNullableParameter');
        $parameters = $this->generator->getPathDescriber()->generatePathOperationParameters(
            $method,
            null,
            false
        );

        $this->assertCount(1, $parameters);
        $this->assertSame('query', $parameters[0]->in);
        $this->assertSame('string', $parameters[0]->schema->type);
        $this->assertTrue($parameters[0]->schema->nullable);
        $this->assertTrue($parameters[0]->required);
    }

    public function testLiteralDefaultIsNotMistakenForNullAndIsPreserved(): void
    {
        $method = new ReflectionMethod($this, 'nativeDefaultParameter');
        $parameters = $this->generator->getPathDescriber()->generatePathOperationParameters(
            $method,
            null,
            false
        );

        $this->assertCount(1, $parameters);
        $this->assertNotSame(true, $parameters[0]->required);
        $this->assertNotSame(true, $parameters[0]->schema->nullable);
        $this->assertSame(0, $parameters[0]->schema->default);
    }

    public function testExplicitBodyParameterCreatesRawRequestBodySchema(): void
    {
        $method = new ReflectionMethod($this, 'documentedExplicitBody');
        $docBlock = $this->generator->getDocBlockFactory()->create($method->getDocComment());
        $requestBody = $this->generator->getPathDescriber()->generatePathOperationBody(
            $method,
            $docBlock,
            false,
            false
        );

        $this->assertInstanceOf(RequestBody::class, $requestBody);
        $this->assertTrue($requestBody->required);
        $this->assertSame('integer', $requestBody->content[0]->schema->type);
    }

    public function testApiParameterTagDocumentsValuesOutsideRunSignature(): void
    {
        $method = new ReflectionMethod($this, 'documentedExternalParameters');
        $docBlock = $this->generator->getDocBlockFactory()->create($method->getDocComment());
        $parameters = $this->generator->getPathDescriber()->generatePathOperationParameters(
            $method,
            $docBlock,
            false
        );
        $parametersByName = [];
        foreach ($parameters as $parameter) {
            $parametersByName[$parameter->name] = $parameter;
        }

        $this->assertSame('header', $parametersByName['X-Client-Version']->in);
        $this->assertSame('cookie', $parametersByName['locale']->in);
        $this->assertSame('query', $parametersByName['tenant']->in);
        $this->assertTrue($parametersByName['tenant']->required);
        $this->assertSame('uuid', $parametersByName['X-Client-Version']->schema->format);

        $requestBody = $this->generator->getPathDescriber()->generatePathOperationBody(
            $method,
            $docBlock,
            false,
            false
        );
        $this->assertInstanceOf(RequestBody::class, $requestBody);
        $this->assertTrue($requestBody->required);
        $this->assertSame('object', $requestBody->content[0]->schema->type);
        $this->assertInstanceOf(AdditionalProperties::class, $requestBody->content[0]->schema->additionalProperties);
        $this->assertSame('integer', $requestBody->content[0]->schema->additionalProperties->type);
    }

    public function testLocationSpecificTagsDocumentExternalParameters(): void
    {
        $method = new ReflectionMethod($this, 'documentedExternalLocationTags');
        $docBlock = $this->generator->getDocBlockFactory()->create($method->getDocComment());
        $parameters = $this->generator->getPathDescriber()->generatePathOperationParameters(
            $method,
            $docBlock,
            false
        );
        $parametersByName = [];
        foreach ($parameters as $parameter) {
            $parametersByName[$parameter->name] = $parameter;
        }

        $this->assertSame('header', $parametersByName['X-Request-Id']->in);
        $this->assertSame('uuid', $parametersByName['X-Request-Id']->schema->format);
        $this->assertSame('cookie', $parametersByName['locale']->in);
        $this->assertSame('query', $parametersByName['tenant']->in);
        $this->assertTrue($parametersByName['tenant']->required);

        $requestBody = $this->generator->getPathDescriber()->generatePathOperationBody(
            $method,
            $docBlock,
            false,
            false
        );
        $this->assertInstanceOf(RequestBody::class, $requestBody);
        $this->assertTrue($requestBody->required);
        $this->assertSame('object', $requestBody->content[0]->schema->type);
        $bodyProperty = $requestBody->content[0]->schema->properties[0];
        $this->assertSame('payload', $bodyProperty->property);
        $this->assertInstanceOf(AdditionalProperties::class, $bodyProperty->additionalProperties);
        $this->assertSame('integer', $bodyProperty->additionalProperties->type);
    }

    public function testRequestBodyTagDescribesTheWholePayload(): void
    {
        $method = new ReflectionMethod($this, 'documentedWholeRequestBody');
        $docBlock = $this->generator->getDocBlockFactory()->create($method->getDocComment());
        $requestBody = $this->generator->getPathDescriber()->generatePathOperationBody(
            $method,
            $docBlock,
            false,
            false
        );

        $this->assertInstanceOf(RequestBody::class, $requestBody);
        $this->assertTrue($requestBody->required);
        $schema = $requestBody->content[0]->schema;
        $this->assertSame('object', $schema->type);
        $this->assertSame('token', $schema->properties[0]->property);
        $this->assertSame(['token'], $schema->required);
    }

    public function testOneRunArgumentCanBeAcceptedFromQueryOrBody(): void
    {
        $method = new ReflectionMethod($this, 'documentedQueryOrBodyParameter');
        $docBlock = $this->generator->getDocBlockFactory()->create($method->getDocComment());
        $parameters = $this->generator->getPathDescriber()->generatePathOperationParameters(
            $method,
            $docBlock,
            false
        );
        $this->assertCount(1, $parameters);
        $this->assertSame('query', $parameters[0]->in);
        $this->assertNotSame(true, $parameters[0]->required);

        $requestBody = $this->generator->getPathDescriber()->generatePathOperationBody(
            $method,
            $docBlock,
            false,
            false
        );
        $this->assertInstanceOf(RequestBody::class, $requestBody);
        $this->assertNotSame(true, $requestBody->required);
        $this->assertSame('value', $requestBody->content[0]->schema->properties[0]->property);
    }

    public function testUnmarkedPrimitiveRemainsQueryAndUnmarkedComplexCanUseLegacyBodyInference(): void
    {
        $method = new ReflectionMethod($this, 'documentedLegacyLocations');
        $docBlock = $this->generator->getDocBlockFactory()->create($method->getDocComment());
        $parameters = $this->generator->getPathDescriber()->generatePathOperationParameters(
            $method,
            $docBlock,
            false
        );
        $this->assertCount(1, $parameters);
        $this->assertSame('query', $parameters[0]->in);

        $requestBody = $this->generator->getPathDescriber()->generatePathOperationBody(
            $method,
            $docBlock,
            false,
            true
        );
        $this->assertInstanceOf(RequestBody::class, $requestBody);
        $this->assertSame('object', $requestBody->content[0]->schema->type);
        $this->assertSame('filters', $requestBody->content[0]->schema->properties[0]->property);
    }

    public function testRoutePlaceholderIsPathAndConfiguredBodyChangesGetToPost(): void
    {
        $this->generator->changeSetting(DefaultGenerator::PARSE_PARAMETERS_FROM_ENDPOINT, true);
        $this->generator->changeSetting(DefaultGenerator::TREAT_COMPLEX_ARGUMENTS_AS_BODY, false);
        $this->generator->changeSetting(DefaultGenerator::CHANGE_GET_TO_POST_FOR_COMPLEX_PARAMETERS, true);

        $route = new Endpoint([
            'id' => '/resources/{resource_id}',
            'callback' => [self::class, 'documentedParameterLocations'],
            'httpMethod' => 'GET',
        ]);
        $routePath = $this->generator->generatePath($route);

        $this->assertInstanceOf(PathItem::class, $routePath);
        $this->assertSame('path', $routePath->get->parameters[0]->in);
        $this->assertTrue($routePath->get->parameters[0]->required);

        $bodyRoute = new Endpoint([
            'id' => '/payload',
            'callback' => [self::class, 'documentedExplicitBody'],
            'httpMethod' => 'GET',
        ]);
        $bodyPath = $this->generator->generatePath($bodyRoute);

        $this->assertSame('post', $bodyRoute->httpMethod);
        $this->assertNotNull($bodyPath->post->requestBody);
        $this->assertTrue($bodyPath->post->requestBody->required);
        $this->assertSame('integer', $bodyPath->post->requestBody->content[0]->schema->type);

        $multipleBodyRoute = new Endpoint([
            'id' => '/bulk',
            'callback' => [self::class, 'documentedMultipleBody'],
            'httpMethod' => 'POST',
        ]);
        $multipleBodyPath = $this->generator->generatePath($multipleBodyRoute);
        $bodySchema = $multipleBodyPath->post->requestBody->content[0]->schema;
        $this->assertSame('object', $bodySchema->type);
        $this->assertCount(2, $bodySchema->properties);
        $this->assertSame(['title', 'count'], $bodySchema->required);

        $inferredPathRoute = new Endpoint([
            'id' => '/resources/{resource_id}',
            'callback' => [self::class, 'documentedInferredPath'],
            'httpMethod' => 'GET',
        ]);
        $inferredPath = $this->generator->generatePath($inferredPathRoute);
        $this->assertSame('path', $inferredPath->get->parameters[0]->in);
        $this->assertTrue($inferredPath->get->parameters[0]->required);
    }

    public function testUnknownClassReturnsNullInsteadOfCausingReturnTypeError(): void
    {
        $notices = [];
        $this->generator->setOnNoticeCallback(static function (string $message) use (&$notices): void {
            $notices[] = $message;
        });

        $schema = $this->describe('MissingResponseClassForRegressionTest');

        $this->assertNull($schema);
        $this->assertNotEmpty($notices);
        $this->assertStringContainsString('MissingResponseClassForRegressionTest', $notices[0]);
    }

    /**
     * @return array{success: bool, payload?: array<string, int>}
     * Response payload.
     */
    public function documentedShapeResponse(): array
    {
        return [];
    }

    /**
     * @return array<string, int>
     * Map response.
     */
    public function documentedGenericMapResponse(): array
    {
        return [];
    }

    /**
     * @param array<string, int> $input Map request.
     */
    public function documentedMapParameter(array $input): void
    {
    }

    /**
     * @param int $resource_id Resource identifier.
     * @pathParam $resource_id
     * @param string|null $filter Optional filter.
     * @queryParam $filter
     * @headerParam string $request_id name=X-Request-Id format=uuid optional Request tracking identifier.
     * @cookieParam string $locale optional Preferred locale.
     * @queryParam array<string, int> $filters Explicit complex query parameter.
     */
    public function documentedParameterLocations(
        int $resource_id,
        ?string $filter = null,
        string $request_id = 'request-id',
        string $locale = 'en',
        array $filters = []
    ): void {
    }

    /**
     * @param int $payload [body] Raw scalar payload.
     */
    public function documentedExplicitBody(int $payload): void
    {
    }

    /**
     * @apiParameter string $clientVersion [header:X-Client-Version] optional format=uuid Версия клиента.
     * @apiParameter string $locale [cookie] optional Язык интерфейса.
     * @apiParameter int $tenant [query] required Идентификатор организации.
     * @apiParameter array<string, int> $payload [body] required Данные запроса.
     */
    public function documentedExternalParameters(): void
    {
    }

    /**
     * @headerParam string $request_id name=X-Request-Id format=uuid optional Correlation identifier.
     * @cookieParam string $locale optional Interface locale.
     * @queryParam int $tenant required Organization ID.
     * @bodyParam array<string, int> $payload required Request payload.
     */
    public function documentedExternalLocationTags(): void
    {
    }

    /** @requestBody array{token: string} required Token payload. */
    public function documentedWholeRequestBody(): void
    {
    }

    /**
     * @param string $value Value accepted from either source.
     * @queryParam $value
     * @bodyParam $value
     */
    public function documentedQueryOrBodyParameter(string $value): void
    {
    }

    /**
     * @param string $title [body] Item title.
     * @param int $count [body] Item count.
     */
    public function documentedMultipleBody(string $title, int $count): void
    {
    }

    /**
     * @param int $resource_id Resource identifier.
     */
    public function documentedInferredPath(int $resource_id): void
    {
    }

    /**
     * @param string $search Search text.
     * @param array<string, int> $filters Filter map.
     */
    public function documentedLegacyLocations(string $search, array $filters = []): void
    {
    }

    public function nativeNullableParameter(?string $search): void
    {
    }

    public function nativeDefaultParameter(int $page = 0): void
    {
    }

    private function generateResponsesFor(string $methodName): array
    {
        $method = new ReflectionMethod($this, $methodName);
        $docBlock = $this->generator->getDocBlockFactory()->create($method->getDocComment());

        return $this->generator->getPathDescriber()->generatePathMethodResponsesFromDocBlock(
            $method,
            $docBlock,
            null
        );
    }

    private function describe(string $type): ?Schema
    {
        $nullable = false;
        $kind = null;

        return $this->generator->getTypeDescriber()->generateSchemaForType(
            self::class,
            $type,
            null,
            $nullable,
            null,
            $kind
        );
    }
}

class TestableDefaultGenerator extends DefaultGenerator
{
    public function generatePath(Endpoint $endpoint): PathItem
    {
        $method = new \ReflectionMethod(DefaultGenerator::class, 'generateAnnotationForPath');
        $method->setAccessible(true);

        return $method->invoke($this, $endpoint);
    }
}
