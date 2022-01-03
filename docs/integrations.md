# Integrations
## Yii2

- A scraper - [`\wapmorgan\OpenApiGenerator\Integration\Yii2CodeScraper`](../src/Integration/Yii2CodeScraper.php)

Will be parsed all application and modules controllers for inline and external actions:

| Yii2 entity                                                            | OpenApi entity                                        | id                                                                                           | phpDoc tags                                                             |
|------------------------------------------------------------------------|-------------------------------------------------------|----------------------------------------------------------------------------------------------|-------------------------------------------------------------------------|
| Every module                                                           | a specification (separate openapi-specification file) | @alias if present, folder name otherwise                                                     | @alias, @description, @docs                                             |
| Every controller                                                       | a section (tag in openapi terms)                      | class name: CamelCaseController to lower/case                                                | @title, @description, @docs                                             |
| Every controller action (`actionNNN()` method or entry in `actions()`) | an endpoint                                           | @alias if present, **NNNN** from `actionNNNN` name if inline, key from `actions()` otherwise | @alias, @auth, @param, @paramExample, @paramEnum, @paramFormat, @result |

Options:
- `bool $scrapeModules`, `bool $scrapeApplication` - scan modules or/and application controllers
- `bool $scanControllerInlineActions`, `bool $scanControllerExternalActions` - scan inline or/and external actions in controllers

## Slim

- A scraper - [`\wapmorgan\OpenApiGenerator\Integration\SlimCodeScraper`](../src/Integration/SlimCodeScraper.php)

## Laravel

- A scraper - [`\wapmorgan\OpenApiGenerator\Integration\LaravelCodeScraper`](../src/Integration/LaravelCodeScraper.php)
