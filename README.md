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

1. You create your own _scraper_ (a class, inheriting `DefaultScraper`), which should return a special result with:
    - list of your API specifications (~ separate OpenApi-files)
    - list of your API specification configurations (paths, tags, security schemes, ...)
    - and so on.

    In two words, you need to create a scraper that will find all API endpoints of your application, collect them and pass it in special format.

2. You pass it to a generator, it generates ready-to-use OpenApi-specifications.
3. You save these specifications in different files / places or move to different hosts.

For example go to [How it works](docs/how_it_works.md) section.

## How to Scrape
Go to [Scraper result](docs/scraper_result.md) section
