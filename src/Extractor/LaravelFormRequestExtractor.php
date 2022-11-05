<?php
namespace wapmorgan\OpenApiGenerator\Extractor;

use Illuminate\Http\Request;
use Illuminate\Validation\Rules\In;
use OpenApi\Annotations\Items;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\Schema;
use OpenApi\Generator;
use wapmorgan\OpenApiGenerator\ReflectionsCollection;

class LaravelFormRequestExtractor extends ExtractorSkeleton
{
    public function extract(\ReflectionMethod $method, \ReflectionParameter $parameter, &$required = [])
    {
        $type = $parameter->getType()->getName();
        $form_request = call_user_func([$type, 'createFrom'], new Request());
        $rules = $form_request->rules();

        $parameters = [];
        foreach ($rules as $attribute => $attribute_rules)
        {
            $parameter = new Parameter([
                'parameter' => $attribute,
                'name' => $attribute,
            ]);
            if (is_string($attribute_rules)) {
                $attribute_rules = explode('|', $attribute_rules);
            }
            foreach ($attribute_rules as $attribute_rule) {
                switch ($attribute_rule) {
                    case 'required':
                        $parameter->required = true;
                        break;
                    case 'string':
                        $parameter->schema = new Schema(['type' => 'string']);
                        break;
                    case 'boolean':
                        $parameter->schema = new Schema(['type' => 'boolean']);
                        break;
                    case 'numeric':
                    case 'integer':
                        $parameter->schema = new Schema(['type' => 'number']);
                        break;
                    case 'array':
                        $parameter->schema = new Schema(['type' => 'array']);
                        break;
                    default:
                        if (!is_object($attribute_rule)) {
                            break;
                        }

                        switch (get_class($attribute_rule)) {
                            case In::class:
                                $values = ReflectionsCollection::getProtectedProperty($attribute_rule, 'values');

                                if ($parameter->schema === Generator::UNDEFINED) {
                                    $parameter->schema = new Schema([
                                        'type' => 'string',
                                        'enum' => $values,
                                    ]);
                                } else if ($parameter->schema->type === 'array') {
                                    $parameter->schema->items = new Items(['type' => 'string']);
                                    $parameter->schema->enum = $values;
                                }
                                break;
                        }
                }
            }
            $parameters[] = $parameter;
        }

        return $parameters;
    }
}
