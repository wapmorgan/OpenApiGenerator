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
use wapmorgan\OpenApiGenerator\Scraper\DefaultScraper;
use wapmorgan\OpenApiGenerator\Scraper\Result\Result;
use wapmorgan\OpenApiGenerator\Scraper\Result\Endpoint;
use wapmorgan\OpenApiGenerator\Scraper\Result\Specification;

class OpenApiScraper extends DefaultScraper {
    public function scrape(): Result
    {
        $result = new Result([
            'specifications' => [
                new Specification([
                    'version' => 'main',
                    'description' => 'My API',
                    'paths' => [],
                ]),
            ],
        ]);

        $main_controller = DefaultController::class;
        // generate list of ALL API actions
        $class_reflection = new ReflectionClass($main_controller);
        foreach ($class_reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method_reflection) {
            if (strncmp($method_reflection->getName(), 'action', 6) !== 0) {
                continue;
            }

            // transform actionIndex -> index
            $action_uri = strtolower(substr($method_reflection->getName(), 6));

            // add path item
            $result->specifications[0]->endpoints[] = new Endpoint([
                'id' => 'default/'.$action_uri,
                'actionCallback' => [$main_controller, $method_reflection->getName()],
            ]);
        }

        return $result;
    }
}
```

2. Write script that generates configuration and saves it in OpenApi 3.0 format in `main.yaml` format.
```php
$scraper = new OpenApiScraper();
$generator = new \wapmorgan\OpenApiGenerator\Generator\DefaultGenerator();
$result = $generator->generate($scraper);
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
