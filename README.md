# What is it?
It is OpenApi configuration generator working with origin source code.
It generates yaml-files with [OpenApi](https://swagger.io/docs/specification/about/) 3.0 configuration from source code.

Main purpose of this library is to simplify OpenApi-file generation for existing API with a lot of methods and specially avoid manual writing of it. 

# How it works?

1. You create your own _scraper_ (a class, inheriting `DefaultScrape`), which should return a special result with:
    - list of your API specifications (~ separate OpenApi-files)
    - list of your API specification configurations (paths, tags, security schemes, ...)

2. You pass it to a generator, it generates ready-to-use OpenApi-specifications.
3. You save these specifications in different files / places or move to different hosts.

# Example of how it works

Let's create a simple API with 3 methods and set-up automatic OpenApi-configuration generation in 3 steps:

0. Create one controller with methods:
```php
class DefaultController {
    /**
     * Get list of rows (their ids)
     * @return int[] List of rows ID
     */
    public function actionIndex() {
        // ...
    }

    /**
     * Save data to row by its ID
     * @param string $data Raw data to save
     * @param int $id ID of row to save
     * @return boolean
     */
    public function actionSave($data, $id) {
    // ...
    }

    /**
     * Get row data by its ID
     * @param int $id ID of row to get
     * @return string Data of row
     */
    public function actionGet($id) {
    // ...
    }


}
```

1. Create **a scraper**:

```php
class OpenApiScraper extends DefaultScrapper {
    public function scrape(): Result
    {
        $result = new Result();
        $result->specifications[0] = new ResultSpecification();
        $result->specifications[0]->version = 'main';
        $result->specifications[0]->description = 'My API';

        $main_controller = DefaultController::class;
        // generate list of ALL API actions
        $class_reflection = new ReflectionClass($main_controller);
        foreach ($class_reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method_reflection) {
            if (!preg_match('~^action(?<action>.+)$~', $method_reflection->getName(), $matches)) {
                continue;
            }
            $action_uri = strtolower(substr($matches['action'], 0, 1)).substr($matches['action'], 1);
            $result->specifications[0]->paths[] = new ResultPath([
                'id' => 'default/'.$action_uri,
                'actionCallback' => [$main_controller, $method_reflection->getName()],
            ]);
        }
        $result->specifications[0]->totalPaths = count($result->specifications[0]->paths);

        return $result;
    }
}
```

2. Write script that generates configuration and saves it in OpenApi 3.0 format in `main.yaml` format.
```php
$scraper = new OpenApiScraper();
$generator = new \wapmorgan\OpenApiGenerator\Generator\DefaultGenerator();
$result = $generator->generate($scraper->scrape());
file_put_contents('main.yaml', $result->specifications[0]->specification->toYaml());
```

In `main.yaml` will be saved OpenApi-3.0 specification like this:
```yaml
openapi: 3.0.0
info:
  title: 'My API'
  version: main
servers: []
paths:
  default/index:
    get:
      tags: []
      summary: 'Get list of rows (their ids)'
      description: ''
      operationId: default/index-GET
      parameters: []
      responses:
        '200':
          description: 'Successful response'
          content:
            application/json:
              schema:
                description: "\nList of rows ID"
                type: array
                items:
                  type: integer
                nullable: false
  default/save:
    get:
      tags: []
      summary: 'Save data to row by its ID'
      description: ''
      operationId: default/save-GET
      parameters:
        -
          name: data
          in: query
          description: 'Raw data to save'
          required: true
          schema:
            type: string
            nullable: false
        -
          name: id
          in: query
          description: 'ID of row to save'
          required: true
          schema:
            type: integer
            nullable: false
      responses:
        '200':
          description: 'Successful response'
          content:
            application/json:
              schema:
                type: boolean
                nullable: false
  default/get:
    get:
      tags: []
      summary: 'Get row data by its ID'
      description: ''
      operationId: default/get-GET
      parameters:
        -
          name: id
          in: query
          description: 'ID of row to get'
          required: true
          schema:
            type: integer
            nullable: false
      responses:
        '200':
          description: 'Successful response'
          content:
            application/json:
              schema:
                description: "\nData of row"
                type: string
                nullable: false
components:
  securitySchemes: {  }
tags: []

```


