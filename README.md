# What is it?
It is OpenApi configuration generator working with origin source code.
It generates yaml-files with [OpenApi](https://swagger.io/docs/specification/about/) 3.0 configuration from source code.

[![Latest Stable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/stable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![Latest Unstable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/unstable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![License](https://poser.pugx.org/wapmorgan/openapi-generator/license)](https://packagist.org/packages/wapmorgan/openapi-generator)

Main purpose of this library is to simplify OpenApi-file generation for existing API with a lot of methods and specially avoid manual writing of it.

### ToDo
- [ ] Describe all scraper functions
- [ ] Support for few operations on one endpoint
- [ ] Extracting class types into components

### Limitations
- Only query parameters supported (`url?param1=...&param2=...`)
- Only one response type supported - HTTP 200 response
- No support for parameters' / fields' / properties' `format`, `example` and other validators.

# How it works?

1. You create your own _scraper_ (a class, inheriting `DefaultScraper`), which should return a special result with:
    - list of your API specifications (~ separate OpenApi-files)
    - list of your API specification configurations (paths, tags, security schemes, ...)
    - and so on.

    In two words, you need to create a scraper that will find all API endpoints of your application, collect them and pass it in special format.

2. You pass it to a generator, it generates ready-to-use OpenApi-specifications.
3. You save these specifications in different files / places or move to different hosts.

# Example of how it works

Let's create a simple API with 3 methods and set-up automatic OpenApi-configuration generation in 3 steps:

0. Create one controller with methods:
```php
class DefaultController {
    /**
     * Get list of rows (their ids)
     * @return int[] List of rows ID
     */
    public function actionIndex() {
        // ...
    }

    /**
     * Save data to row by its ID
     * @param string $data Raw data to save
     * @param int $id ID of row to save
     * @return boolean
     */
    public function actionSave($data, $id) {
    // ...
    }

    /**
     * Get row data by its ID
     * @param int $id ID of row to get
     * @return string Data of row
     */
    public function actionGet($id) {
    // ...
    }


}
```

1. Create **a scraper**:

```php
use wapmorgan\OpenApiGenerator\Scraper\DefaultScrapper;
use wapmorgan\OpenApiGenerator\Scraper\Result\Result;
use wapmorgan\OpenApiGenerator\Scraper\Result\ResultPath;
use wapmorgan\OpenApiGenerator\Scraper\Result\ResultSpecification;

class OpenApiScraper extends DefaultScrapper {
    public function scrape(): Result
    {
        $result = new Result([
            'specifications' => [
                new ResultSpecification([
                    'version' => 'main',
                    'description' => 'My API',
                    'paths' => [],
                ]),
            ],
        ]);

        $main_controller = DefaultController::class;
        // generate list of ALL API actions
        $class_reflection = new ReflectionClass($main_controller);
        foreach ($class_reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method_reflection) {
            if (strncmp($method_reflection->getName(), 'action', 6) !== 0) {
                continue;
            }

            // transform actionIndex -> index
            $action_uri = strtolower(substr($matches['action'], 0, 1)).substr($matches['action'], 1);

            // add path item
            $result->specifications[0]->paths[] = new ResultPath([
                'id' => 'default/'.$action_uri,
                'actionCallback' => [$main_controller, $method_reflection->getName()],
            ]);
        }
        $result->specifications[0]->totalPaths = count($result->specifications[0]->paths);

        return $result;
    }
}
```

2. Write script that generates configuration and saves it in OpenApi 3.0 format in `main.yaml` format.
```php
$scraper = new OpenApiScraper();
$generator = new \wapmorgan\OpenApiGenerator\Generator\DefaultGenerator();
$result = $generator->generate($scraper->scrape());
file_put_contents('main.yaml', $result->specifications[0]->specification->toYaml());
```

In `main.yaml` will be saved OpenApi-3.0 specification like this:
```yaml
openapi: 3.0.0
info:
  title: 'My API'
  version: main
servers: []
paths:
  default/index:
    get:
      tags: []
      summary: 'Get list of rows (their ids)'
      description: ''
      operationId: default/index-GET
      parameters: []
      responses:
        '200':
          description: 'Successful response'
          content:
            application/json:
              schema:
                description: "\nList of rows ID"
                type: array
                items:
                  type: integer
                nullable: false
  default/save:
    get:
      tags: []
      summary: 'Save data to row by its ID'
      description: ''
      operationId: default/save-GET
      parameters:
        -
          name: data
          in: query
          description: 'Raw data to save'
          required: true
          schema:
            type: string
            nullable: false
        -
          name: id
          in: query
          description: 'ID of row to save'
          required: true
          schema:
            type: integer
            nullable: false
      responses:
        '200':
          description: 'Successful response'
          content:
            application/json:
              schema:
                type: boolean
                nullable: false
  default/get:
    get:
      tags: []
      summary: 'Get row data by its ID'
      description: ''
      operationId: default/get-GET
      parameters:
        -
          name: id
          in: query
          description: 'ID of row to get'
          required: true
          schema:
            type: integer
            nullable: false
      responses:
        '200':
          description: 'Successful response'
          content:
            application/json:
              schema:
                description: "\nData of row"
                type: string
                nullable: false
components:
  securitySchemes: {  }
tags: []

```

# Scraper result
You should pass a `wapmorgan\OpenApiGenerator\Scraper\Result\Result` instance in Generator. All following class names
 are in `wapmorgan\OpenApiGenerator` namespace.

Properties of `Scraper\Result\Result`:
- `Scraper\Result\ResultSpecification[]` **$specifications** - list of specifications for
 generation. Every specification:
    - `string` **$version** - unique ID of specification.
    - `string` **$description** - summary of specification.
    - [`ResultPath[]`](#resultpath) **$paths** - list of API endpoints.
    - [`ResultServer[]`](#resultserver) **$servers** - list of servers of your API.
    - [`ResultTag[]`](#resulttag) **$tags** - list of tags of your API with description and other properties.
    - [`ResultSecurityScheme[]`](#resultsecurityscheme) **$securitySchemes** - list of security schemes.

As you can see, a lot of them is similar to [original OpenApi 3 blocks](https://swagger.io/docs/specification/basic-structure/).

### `ResultPath`
Basically, you can only fill `$paths` with `ResultPath` instances. It has following properties:

- `string $id` - original ID of path and it's endpoint (after server api url).
- `string $httpMethod = 'GET'` - HTTP-method applicable for this endpoint. **Few methods is not supported now**.
- `callable $actionCallback` - callback for this endpoint. Possible types for callback:
    * `[class, method]` - controller method
    This callback will be scanned and used to generate:
    1. List of endpoint parameters
    2. All possible resulting values
- `string|null $pathResultWrapper` - class inheriting `\wapmorgan\OpenApiGenerator\Scraper\DefaultPathResultWrapper
`, which will be used as endpoint result wrapper.
- `string[]|null $securitySchemes` - list of security schemes applicable to this endpoint. Just put here names of
 security schemes applicable for this path.
- `string[]|null $tags` - list of tags for this endpoint. Just put here names of tags applicable for this
 path.

For detailed information go to [paths description](https://swagger.io/docs/specification/paths-and-operations/).

### `ResultServer`
Properties of every server for your API:
- `string` **$url** - URL of server
- `string|null` **$description** - name/description of server.

For detailed information go to [servers description](https://swagger.io/docs/specification/api-host-and-base-path/).

### `ResultTag`
Every tag for your specification may have this instance with extra description.

You should define tags manually and then link them to every paths.

Properties:
- `string` **$name** - name of tag (like `user` or `auth`).
- `string|null` **$description** - description of tag.
- `string|null` **$externalDocs** - URL to documentation for this tag on external resource.

For detailed information go to [tags description](https://swagger.io/docs/specification/grouping-operations-with-tags/).

### `ResultSecurityScheme`

Every method of authentication should have a security scheme definition.

Properties of security scheme:
- `string` **$id** - unique ID of security scheme.
- `string` **$type** - type of scheme.
- `string` **$in**.
- `string` **$name** - Name for security scheme parameter.
- `string|null` **$description** - description of security scheme.

For description of all parameters and they meaning go to [explanation of authentication in OpenApi documentation](https://swagger.io/docs/specification/authentication/).
