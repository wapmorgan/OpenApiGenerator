# How to use

## Console commands
### Scrape
Uses your scraper and returns list of endpoints.

Usage:

`./vendor/bin/openapi-generator scrape [--scraper SCRAPER] [--specification SPECIFICATION]`, where `<scraper>` is a class or file with scraper.

Example:

`./vendor/bin/openapi-generator scrape --scraper components/openapi/OpenApiScraper.php --specification site`.

### Generate
Generates openapi-files from scraper and generator.

Usage:

`./vendor/bin/openapi-generator generate [--scraper SCRAPER] [-g|--generator GENERATOR] [--specification SPECIFICATION] [-f|--format FORMAT] [--inspect] [--] [<output>]`:

- `generator` - file or class of Generator
- `specification` - regex for module
- `output` - directory for output files

Example:

- `./vendor/bin/openapi-generator generate --scraper components/openapi/OpenApiScraper.php components/openapi/OpenApiGenerator.php`.
- `./vendor/bin/openapi-generator generate --scraper laravel --generator wapmorgan\\OpenApiGenerator\\Generator\\DefaultGenerator`.
