# What is it?
It is OpenApi configuration generator working with origin source code.
It generates yaml-files with [OpenApi](https://swagger.io/docs/specification/about/) 3.0 configuration from source code.

[![Latest Stable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/stable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![Latest Unstable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/unstable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![License](https://poser.pugx.org/wapmorgan/openapi-generator/license)](https://packagist.org/packages/wapmorgan/openapi-generator)

Main purpose of this library is to simplify OpenApi-file generation for existing API with a lot of methods and specially avoid manual writing of it.

**ToDo**:
- [ ] Describe all scraper functions
- [ ] Support for few operations on one endpoint
- [ ] Extracting class types into components

**Limitations**:
- Only query parameters supported (`url?param1=...&param2=...`)
- Only one response type supported - HTTP 200 response
- No support for parameters' / fields' / properties' `format`, `example` and other validators.

## How it works

Next sections show how it works (simplified)

### What it takes
```php
class MainController extends Controller {
    /**
     * Sends test message
     *
     * Sends simple text message to an admin
     * @param string $topic Topic of the message
     * @param string $text Text of the message
     * @return bool Result of send
     */
    public function actionTestSendMessage($topic, $text)
    {
        // ...
        // ... here goes some work ...
        // ...
        return true;
    }
}
```

### What it generates

``yaml
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
``

### What it parses (from code)

- Action summary (first line of php-doc) and description
- Action parameters: php-doc ones (`@param`) and ones in action method signatures (`string $text`).
Also, there is support for non-usual php-doc tags: `@paramEnum`, `@paramExample` and `@auth`.
- Action result (`@return SendMessageResponse`)

## How to use it

1. You create your own _scraper_ (a class, inheriting `DefaultScraper`), which should return a special result with:
    - list of your API specifications (~ separate OpenApi-files)
    - list of your API specification configurations (paths, tags, security schemes, ...)
    - and so on.

    In two words, you need to create a scraper that will find all API endpoints of your application, collect them and pass it in special format.

2. You pass it to a generator, it generates ready-to-use OpenApi-specifications.
3. You save these specifications in different files / places or move to different hosts.

For example go to [How it works](docs/how_it_works.md) section.

### How to Scrape
Go to [Scraper result](docs/scraper_result.md) section

## Examples
Example of Yii2 integration:

- A scraper - [`\wapmorgan\OpenApiGenerator\Integration\Yii2\CodeScraper`](Integration/Yii2/CodeScraper.php)
- A console controller - [`\wapmorgan\OpenApiGenerator\Integration\Yii2\GeneratorController`](Integration/Yii2/GeneratorController.php)
