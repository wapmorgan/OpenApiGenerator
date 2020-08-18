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

1. You create your own _scraper_, which should return a special result with list of your API endpoints (with tags, security schemes and so on), which in most cases is just a callback.
This callback represents **only** one endpoint of your API.
2. Generator accepts this list and parses information:
    - Callback parameters (from callback signature or callback php-doc)
    - Callback information (from php-doc)
    - Callback result (from php-doc)

If your need full process example, go to [How it works](docs/how_it_works.md) file.
Detailed information about Scraper result: [in another document](docs/scraper_result.md).

## What it can parse
By default it parses only common information.
### from Callback

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

- Summary and full description
- Parameter `$data` that should be string or omitted. Also, it can have one of values: `all`, `one` or `two`. Example of value is `two`.
- Resulting data: an object of class TestResponse (class properties or php-doc will be parsed and described)

# Settings
DefaultGenerator provides list of settings to tune generator.

- `DefaultGenerator::CHANGE_GET_TO_POST_FOR_COMPLEX_PARAMETERS` - if callback has arguments with `object`, `array`, `stdclass`, `mixed` type or class-typed, method of argument will be changed to `POST` and these arguments will be placed as `body` data in json-format.
- `DefaultGenerator::TREAT_COMPLEX_ARGUMENTS_AS_BODY` -
- `DefaultGenerator::PARSE_PARAMETERS_FROM_ENDPOINT` - if callback `id` has macroses (`users/{id}`), these arguments will be parsed as normal callback arguments.
- `DefaultGenerator::PARSE_PARAMETERS_FORMAT_FORMAT_DESCRIPTION` - if php-doc for callback argument in first word after argument variable has one of predefined sub-types (`@param string $arg SUBTYPE Full parameter description `), this will change sub-type in resulting specification.
For example, for `string` format there are subtypes: `date`, `date-time`, `password`, `byte`, `binary`, for `integer` there are: `float`, `double`, `int32`, `int64`.
Also, you can defined custom format with `DefaultGenerator::setCustomFormat($format, $formatConfig)`.

Usage:
```php
$generator->changeSetting(DefaultGenerator::CHANGE_GET_TO_POST_FOR_COMPLEX_PARAMETERS, true);
```

By default, they all are disabled.

# Limitations
- Only query parameters supported (`url?param1=...&param2=...`)
- Only one response type supported - HTTP 200 response
- No support for parameters' / fields' / properties' `format`, `example` and other validators.

# ToDo
- [x] Support for few operations on one endpoint (GET/POST/PUT/DELETE/...).
- [x] Support for body parameters (when parameters are complex objects) - partially.
- [ ] Support for few responses (with different HTTP codes).
- [ ] Extracting class types into separate components (into openapi components).
- [ ] Add `@paramFormat` for specifying parameter format - partially.
