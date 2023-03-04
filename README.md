# What is it?
It is OpenApi configuration generator that works with origin source code.

[![Latest Stable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/stable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![Latest Unstable Version](https://poser.pugx.org/wapmorgan/openapi-generator/v/unstable)](https://packagist.org/packages/wapmorgan/openapi-generator)
[![License](https://poser.pugx.org/wapmorgan/openapi-generator/license)](https://packagist.org/packages/wapmorgan/openapi-generator)

Main purpose of this library is to automatize generation of OpenApi-specification for existing **JSON-API** with a lot of methods. Idea by [@maxonrock](https://github.com/maxonrock).

1. [Open Api generator](#openapigenerator)
   - [Laravel Example](#laravel-example)
   - [How it works](#how-it-works)
   - [How to use](#how-to-use)
   - [Integrations](#integrations)
2. [Extending](#extending)
   - [New scraper](#new-scraper)
   - [Settings](#settings)
   - [Limitations](#limitations)
   - [ToDo](#todo)

# OpenApiGenerator
**What it does?**

It generates [OpenApi 3.0 specification files](https://swagger.io/docs/specification/about/) for your REST JSON-based API
written in PHP from source code directly. You **do not need** to write OpenApi-specification manually.

## Laravel Example

1. Routes:
   ```php
   Route::get('/selector/lists', [\App\Http\Controllers\SelectorController::class, 'lists']);
   Route::post('/selector/select', [\App\Http\Controllers\SelectorController::class, 'select']);
   ```

2. One controller:
   ```php
       /**
        * Returns list of filters
        * @param Request $request
        * @return array
        */
       public function lists(Request $request) {
           return [
   //            'persons' => range(1, 15),
               'persons' => array_keys(Menu::$personsList),
               'tastes' => Menu::$tastes,
               'meat' => Menu::$meat,
               'pizzas' => Menu::$pizzas,
           ];
       }
   
       /**
        * Searches pizzas by criteria
        * @param \App\Http\Requests\SelectPizzas $request
        * @return int[]
        */
       public function select(\App\Http\Requests\SelectPizzas $request) {
           $validated = $request->validated();
           $selector = new Selector();
           $pizzas = $selector->select(
               $validated['city'], $validated['persons'],
               $validated['tastes'] ?? null, $validated['meat'] ?? null,
               $validated['vegetarian'] ?? false, $validated['maxPrice'] ?? null);
   
           return $pizzas;
       }
   ```

3. **Result of generation from code**: two endpoints with description and arguments for `select`.

## How it works

1. **Scraper** collects info about API (tags, security schemes and servers, all endpoints) and contains settings for Generator. Scraper is framework-dependent.
2. **Generator** fulfills openapi-specification with endpoints information by analyzing source code:
    - summary and description of actions
    - parameters and result of actions
   Generator is common. It just receives information from Scraper and analyzes code by Scraper rules.

More detailed process description is in [How it works document](docs/how_it_works.md).

## How to use
Invoke console script to generate openapi for your project (with help of integrations): 

For example, for yii2-project:
1. Run parser on project to analyze files and retrieve info about endpoints
   ```shell
   ./vendor/bin/openapi-generator scrape --scraper yii2 ./
   # And more deeper scan
   ./vendor/bin/openapi-generator generate --scraper yii2 --inspect ./
   ```
2. Generate specification(s) into yaml-files in `api_docs` folder by **specification_name.yml**
   ```shell
   ./vendor/bin/openapi-generator generate --scraper yii2 ./ ./api_docs/
   # Or with your own scraper (child of one of basic scrapers)
   ./vendor/bin/openapi-generator generate --scraper components/api/OpenApiScraper.php ./ ./api_docs/
   ```
3. Deploy swagger with specification (e.g. _api_docs/main.yml_)
   ```shell
    docker run -p 80:8080 -e SWAGGER_JSON=./apis/main.yaml -v $(PWD):/usr/share/nginx/html/apis/ swaggerapi/swagger-ui:v4.15.2    
   ```

More detailed description is in [How to use document](docs/how_to_use.md).

## Integrations
There's few integrations: Yii2, Laravel, Slim. Details is in [Integrations document](docs/integrations.md).
You can write your own integration for framework or your project.

# Extending
## New scraper

You use (or extend) a predefined _scraper_ (see Integrations) or create your own _scraper_ from scratch (extend `DefaultScraper`), which should return a result with list of your API endpoints. Also, your scraper should provide tags, security schemes and so on.

Scraper should return list of **specifications** (for example, list of api versions) with:
- _meta_ - version/description/externalDocs - of specification.
- _servers_ - list of servers (base urls).
- _tags_ - list of tags with description and other properties (categories for endpoints).
- _securitySchemes_ - list of security schemes (authorization types).
- _endpoints_ - list of API endpoints (separate callbacks).

Detailed information about Scraper result: [in another document](docs/scraper_result.md).

## Settings
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

## Limitations
- Only query parameters supported (`url?param1=...&param2=...`) or body json parameters (`{data: 123`).
- Only one response type supported - HTTP 200 response.
- No support for parameters' / fields' / properties' `format`, `example` and other validators.

## ToDo
- [x] Support for few operations on one endpoint (GET/POST/PUT/DELETE/...).
- [x] Support for body parameters (when parameters are complex objects) - partially.
- [ ] Support for few responses (with different HTTP codes).
- [ ] Extracting class types into separate components (into openapi components).
- [ ] Support for other request/response types besides JSON
- [x] Add `@paramFormat` for specifying parameter format - partially.
- [ ] Support for dynamic action arguments in dynamic model
- [ ] Switch 3.0/3.1 (https://www.openapis.org/blog/2021/02/16/migrating-from-openapi-3-0-to-3-1-0)
