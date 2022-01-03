# What is it?
It is OpenApi configuration generator that works with origin source code.

[![Latest Stable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/stable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![Latest Unstable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/unstable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![License](https://poser.pugx.org/wapmorgan/openapi-generator/license)](https://packagist.org/packages/wapmorgan/openapi-generator)

Main purpose of this library is to simplify OpenApi-specification generation for existing API with a lot of methods and especially automatize it to avoid manual changes. Idea by [@maxonrock](https://github.com/maxonrock).

1. [What it does](#what-it-does)
2. [How it works](#how-it-works)
3. [How to use](#how-to-use)
4. [Integrations](#integrations)
5. [New scraper](#new-scraper)
5. [Settings](#settings)
6. [Limitations](#limitations)
7. [ToDo](#todo)

# What it does

It generates [OpenApi 3.0 specificaton files](https://swagger.io/docs/specification/about/) for your REST API written in
PHP from source code based on any framework or written manually, whatever.

# How it works

1. **Scraper** collects info about specifications, tags, security schemes and servers, and lists all endpoints.
2. **Generator** fulfills openapi-specification with endpoints information:
    - summary  and description (first line and rest of php-doc)
    - parameters: from php-doc: `@param`/`@paramEnum`/`@paramExample`/`@paramFormat`,  from function signature: `string $text`.
    - result declared in php-doc (`@return SendMessageResponse`)

More detailed process description is in [How it works](docs/how_it_works.md) document.

# How to use
- Parse and compose list of endpoints - `./vendor/bin/openapi-generator scrape --scraper SCRAPER`.
- Generate specifications - `./vendor/bin/openapi-generator generate --scraper SCRAPER --generator GENERATOR`.
where `SCRAPER` is a class or file with scraper.

More detailed description is in [How to use](docs/how_to_use.md) document.

# Integrations
There's few integrations: yii2, laravel, slim.
More detailed description is in [Integrations](docs/integrations.md) document.

# New scraper

You use (or extend) a predefined _scraper_ (see Integrations) or create your own _scraper_ from scratch (extend `DefaultScraper`), which should return a result with list of your API endpoints. Also, your scraper should provide tags, security schemes and so on.

Scraper should returns list of **specifications** (for example, list of api versions) with data in each _specification_:
- _version_ - unique ID of specification.
- _description_ - summary of specification.
- _externalDocs_ - URL to external docs.
- _servers_ - list of servers (base urls).
- _tags_ - list of tags with description and other properties.
- _securitySchemes_ - list of security schemes (authorization types).
- _endpoints_ - list of API endpoints.

Detailed information about Scraper result: [in another document](docs/scraper_result.md).

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
- [x] Add `@paramFormat` for specifying parameter format - partially.
- [ ] Support for dynamic action arguments in dynamic model
- [ ] Switch 3.0/3.1 (https://www.openapis.org/blog/2021/02/16/migrating-from-openapi-3-0-to-3-1-0)
