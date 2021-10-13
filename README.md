# What is it?
It is OpenApi configuration generator that works with origin source code.

[![Latest Stable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/stable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![Latest Unstable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/unstable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![License](https://poser.pugx.org/wapmorgan/openapi-generator/license)](https://packagist.org/packages/wapmorgan/openapi-generator)

Main purpose of this library is to simplify OpenApi-specification generation for existing API with a lot of methods and especially automatize it to avoid manual changes.

Original idea by [@maxonrock](https://github.com/maxonrock).

1. [What it does](#what-it-does)
2. [How it works](#how-it-works)
    - [What data should a scraper provide](#what-data-should-a-scraper-provide)
    - [Extractable information from code](#extractable-information-from-code)
3. [Integrations](#integrations)
    - [Yii 2](#yii2)
    - [Slim](#slim)
4. [Settings](#settings)
5. [Limitations](#limitations)
6. [ToDo](#todo)

# What it does

It generates [OpenApi 3.0 specificaton files](https://swagger.io/docs/specification/about/) for your REST API written in
PHP from source code based on any framework or written manually, whatever. In last case, you need to write more 
instructions for generator.

# How it works
1. You prepare data (use predefined or write own scraper), that collects:
   - your API _endpoints_
   - specification description, servers, tags, authorization schemas, etc.
2. You pass it to _generator_.
3. _Generator_ parses them, extracts any useful information (_description, parameters, resulting data, ..._) from callbacks:
    - Callback parameters (from callback signature or callback php-doc)
    - Callback information (from php-doc)
    - Callback result (from php-doc or defined explicit)
4. _Generator_ collects all data and compacts it into OpenApi configurations (that are ready-to-use with Swagger and Swagger-UI).

If your need full process example, go to [How it works](docs/how_it_works.md) file.

## What data should a scraper provide

You use (or modify) a predefined _scraper_ or create your own _scraper_, which should return a special result with list of your API endpoints , which in most cases is just a callback.
Also, your scraper should provide tags, security schemes and so on.

Detailed information about Scraper result: [in another document](docs/scraper_result.md).

## Extractable information from code

- Endpoint summary (first line of php-doc) and description (rest of php-doc).
- Endpoint parameters:
    - from php-doc: `@param`
    - from endpoint signatures: `string $text`
    Also, there is support for non-usual php-doc modifications:
    - `@paramEnum`
    - `@paramExample`
    - `@paramFormat`
- Endpoint result:
    - declared in php-doc (`@return SendMessageResponse`)
    - declared in endpoints information by a scraper.

# Integrations
## Yii2

- A scraper - [`\wapmorgan\OpenApiGenerator\Integration\Yii2\CodeScraper`](src/Integration/Yii2/Yii2CodeScraper.php)
- A console command - [`\wapmorgan\OpenApiGenerator\Integration\Yii2\GeneratorController`](src/Integration/Yii2/Yii2GeneratorController.php)

## Slim

- A scraper - [`\wapmorgan\OpenApiGenerator\Integration\Slim\CodeScraper`](src/Integration/Slim/SlimCodeScraper.php)

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
- Only query parameters supported (`url?param1=...&param2=...`) or body json parameters (`{data: 123`).
- Only one response type supported - HTTP 200 response.
- No support for parameters' / fields' / properties' `format`, `example` and other validators.

# ToDo
- [x] Support for few operations on one endpoint (GET/POST/PUT/DELETE/...).
- [x] Support for body parameters (when parameters are complex objects) - partially.
- [ ] Support for few responses (with different HTTP codes).
- [ ] Extracting class types into separate components (into openapi components).
- [ ] Add `@paramFormat` for specifying parameter format - partially.
- [ ] Support for dynamic action arguments in dynamic model
