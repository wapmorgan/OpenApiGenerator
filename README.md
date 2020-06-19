# What is it?
It is OpenApi configuration generator that works with origin source code.
It generates [OpenApi 3.0 specificaton files](https://swagger.io/docs/specification/about/) for your REST API from source code based on any framework or written manually (in this case you need to write more instructions for generator).

[![Latest Stable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/stable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![Latest Unstable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/unstable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![License](https://poser.pugx.org/wapmorgan/openapi-generator/license)](https://packagist.org/packages/wapmorgan/openapi-generator)

Main purpose of this library is to simplify OpenApi-specification generation for existing API with a lot of methods and especially automatize it to avoid manual changes.

Original idea by [@maxonrock](https://github.com/maxonrock).

1. [Purpose](#purpose)
    - [Extractable information from code](#extractable-information-from-code)
2. [Integrations](#integrations)
    - [Yii 2](#yii2)
    - [Slim](#slim)
3. [How it works](#how-it-works)
4. [How to use it](#how-to-use-it)
5. [Limitations](#limitations)
6. [ToDo](#todo)

# Purpose

1. It takes you controller/actions/callbacks for services.
2. Parses them, extract any userful information (description, parameters, resulting data).
3. Collect it and compacts into OpenApi configuration (ready-to-use with Swagger and Swagger-UI).

## Extractable information from code

- Module/Controller summary, `@descripton` and `@docs` tags.
- Action summary (first line of php-doc) and description (rest of php-doc).
- Action parameters: php-doc ones (`@param`) and ones in action method signatures (`string $text`).
Also, there is support for non-usual php-doc like `@paramEnum` or `@paramExample`.
- Action result declared explicit (`@return SendMessageResponse`) or implicit (views or schemas).

# Integrations
## Yii2

- A scraper - [`\wapmorgan\OpenApiGenerator\Integration\Yii2\CodeScraper`](src/Integration/Yii2/CodeScraper.php)
- A console command - [`\wapmorgan\OpenApiGenerator\Integration\Yii2\GeneratorController`](src/Integration/Yii2/GeneratorController.php)

## Slim

- A scraper - [`\wapmorgan\OpenApiGenerator\Integration\Slim\CodeScraper`](src/Integration/Slim/CodeScraper.php)

# How it works
Simplified example of how it works.

*A controller (class) and action (method)*:
```php
class MainController extends Controller {
    /**
     * Sends test message
     *
     * Sends simple text message to an admin
     * @param string $topic Topic of the message
     * @param string $text Text of the message
     * @return ResultSend Result of send
     */
    public function actionTestSendMessage($topic, $text)
    {
        // ...
        // ... here goes some work ...
        // ...
        return new ResultSend([
            'success' => true,
            'datetime' => (new DateTime())->format(DateTime::ATOM),
        ]);
    }
}
```

*Generated openapi spec*:
```yaml
  /main/testSendMessage:
    get:
      tags:
        - main
      summary: 'Sends test message'
      description: 'Sends simple text message to an admin'
      operationId: /main/testSendMessage-GET
      parameters:
        -
          name: topic
          in: query
          description: 'Topic of the message'
          required: true
          schema:
            type: string
            nullable: false
        -
          name: text
          in: query
          description: 'Text of the message'
          required: true
          schema:
            type: string
            nullable: false
      responses:
        '200':
          description: 'Successful response'
          content:
            application/json:
              schema:
                - { properties: { result: { description: "\nResult of send", type: boolean, nullable: false } } }
```

If your need full process example, go to [How it works](docs/how_it_works.md) file.


## What it can parse
### from Controller

All actions within one controller will have with tag of it.

```php
/**
 * @description Main functions
 * @docs http://...
 */
class MainController {
}
```

- `@description` - description for tag.
- `@docs` - URL to page with tag description.

### from Action

```php
/**
 * Action summary
 *
 * Action detailed description
 * on few lines
 * @param string|null $data Data fo action
 * @paramEnum $data all|one|two
 * @paramExample $data two
 * @return TestResponse
 */
public function actionTest($data)
{
  // ...
}
```

- `@paramEnum` lists all values that can be used in parameter. Syntax: `@paramEnum $variable 1st[|2nd[...]]`
- `@paramExample` sets example for parameter. Syntax: `@paramExample $variable string_value`

# How to use it

1. You create your own _scraper_ (a class, inheriting `DefaultScraper`) or extend one of [predefined scrapers](#integrations), which should return a special result with:
    - list of your API specifications (~ separate OpenApi-files)
    - paths, tags, security schemes, ...
    In two words, you need to create a scraper that will find all API endpoints of your application, collect them and pass it in special format.
2. You pass it to a generator, it generates ready-to-use OpenApi-specifications.
3. You save these specifications in different files / places or move to different hosts.

Detailed information about Scraper result: [in another document](docs/scraper_result.md).

# Limitations
- Only query parameters supported (`url?param1=...&param2=...`)
- Only one response type supported - HTTP 200 response
- No support for parameters' / fields' / properties' `format`, `example` and other validators.

# ToDo
- [x] Support for few operations on one endpoint (GET/POST/PUT/DELETE/...).
- [x] Support for body parameters (when parameters are complex objects)
- [ ] Support for few responses (with different HTTP codes).
- [ ] Extracting class types into separate components (into openapi components).
- [ ] Add `@paramFormat` for specifying parameter format.
