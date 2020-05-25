**What result scraper should return**

You should pass a `wapmorgan\OpenApiGenerator\Scraper\DefaultScraper` instance in Generator, which should return a
 `Result` in `scrape()` method.

All following class names are in `wapmorgan\OpenApiGenerator` namespace.

Properties of `Scraper\Result\Result`:
- `Scraper\Result\ResultSpecification[]` **$specifications** - list of specifications for
 generation. Every specification:
    - `string` **$version** - unique ID of specification.
    - `string|null` **$description** - summary of specification.
    - `string|null` **$externalDocs** - URL to external docs.
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
- `string|object|null $pathResult` - Pointer to another type that is the real result of action.
    Can be an object, which will be described as usual complex type (class).
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
