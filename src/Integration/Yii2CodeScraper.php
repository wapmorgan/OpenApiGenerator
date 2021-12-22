<?php
namespace wapmorgan\OpenApiGenerator\Integration;

use ReflectionMethod;
use wapmorgan\OpenApiGenerator\ReflectionsCollection;
use wapmorgan\OpenApiGenerator\Scraper\Endpoint;
use wapmorgan\OpenApiGenerator\Scraper\Result;
use wapmorgan\OpenApiGenerator\Scraper\SecurityScheme\ApiKeySecurityScheme;
use wapmorgan\OpenApiGenerator\Scraper\Server;
use wapmorgan\OpenApiGenerator\Scraper\Specification;
use wapmorgan\OpenApiGenerator\Scraper\Tag;
use wapmorgan\OpenApiGenerator\ScraperSkeleton;
use Yii;

class Yii2CodeScraper extends ScraperSkeleton
{
    public $excludedModules = [];

    public $scrapeModules = true;
    public $scrapeApplication = false;

    public $moduleNamePattern;
    public $controllerInModuleClassPattern = '~^app\\\\modules\\\\(?<moduleId>[a-z0-9_]+)\\\\controllers\\\\(?<controller>[a-z0-9_]+)Controller$~i';
    public $actionAsControllerMethodPattern = '~^action(?<action>[A-Z][a-z0-9_]+)$~i';

    public $securitySchemes = [
        'defaultAuth' => [
            'type' => 'apiKey',
            'in'=> 'query',
            'name' => 'session_id',
            'description' => 'ID сессии',
        ],
    ];

    public $defaultSecurityScheme = 'defaultAuth';

    public $servers = [
        'http://localhost:8080/' => 'Local server',
    ];

    /**
     * @inheritDoc
     * @throws \ReflectionException
     */
    public function scrape(): Result
    {
        $directory = getcwd();
        $this->initializeYiiAutoloader($directory);

        $directories = $this->getControllerDirectories($directory);

        list($total_actions, $controllers) = $this->getActionsList($directories);

        ksort($controllers, SORT_NATURAL);

        $result = new Result();

        foreach ($controllers as $module_id => $module_controllers) {
            $specification = $this->newSpecification($module_id);

            foreach ($module_controllers as $controller_class => $controller_configuration) {
                $controller_reflection = ReflectionsCollection::getClass($controller_class);

                $controller_doc = $controller_reflection->getDocComment();
                $controller_description = $this->getDocParameter($controller_doc, 'description', '');
                $controller_docs = $this->getDocParameter($controller_doc, 'docs', '');
                $specification->tags[] = new Tag([
                    'name' => $controller_configuration['controllerId'],
                    'description' => $controller_description,
                    'externalDocs' => $controller_docs,
                ]);

                foreach ($controller_configuration['actions'] as $controller_action_id => $controller_action_method) {
                    $path = new Endpoint();
                    $path->id = ($controller_configuration['controllerId'] !== 'default' ? '/' . $controller_configuration['controllerId'] : null)
                        . '/' . $controller_action_id;

                    if (is_string($controller_action_method)) {
                        $action_reflection = ReflectionsCollection::getMethod($controller_class, $controller_action_method);
                        $path->callback = [$controller_class, $controller_action_method];
                    }

                    $path->tags[] = $controller_configuration['controllerId'];

                    $action_doc = $action_reflection->getDocComment();
                    if (!empty($action_auth = $this->getDocParameter($action_doc, 'auth', ''))) {
                        $this->ensureSecuritySchemeAdded($specification, $this->defaultSecurityScheme);
                        $path->securitySchemes[] = $this->defaultSecurityScheme;
                    }
                    $specification->endpoints[] = $path;
                }
            }

            $result->specifications[] = $specification;
        }

        return $result;
    }

    /**
     * @param string $directory
     * @return array
     */
    public function getControllerDirectories(string $directory): array
    {
        $directories = [];

        if ($this->scrapeApplication && is_dir($controllers_dir = $directory.'/controllers'))
            $directories[] = $controllers_dir;

        if ($this->scrapeModules && is_dir($modules_dir = $directory.'/modules')) {
            foreach (glob($modules_dir.'/*', GLOB_ONLYDIR) as $module_dir) {
                if (!is_dir($module_dir.'/controllers')) {
                    continue;
                }

                $module_name = basename($module_dir);

                if ($this->specificationPattern !== null && !fnmatch($this->specificationPattern, $module_name)) {
                    $this->notice('Skipping '.$module_name, self::NOTICE_INFO);
                    continue;
                }

                if ($this->specificationAntiPattern !== false && fnmatch($module_name, $this->specificationPattern, true))
                    continue;

                $directories[] = $module_dir.'/controllers';
            }
        }
        natsort($directories);
        return $directories;
    }

    /**
     * @param array $directories
     * @return array
     * @throws \ReflectionException
     */
    public function getActionsList(array $directories)
    {
        $controllers_list = [];

        $total_actions = 0;
        foreach ($directories as $directory) {
            foreach (glob($directory.'/*.php') as $php_file) {
                $before_classes_list = get_declared_classes();
                require_once $php_file;
                $added_classes = array_diff(get_declared_classes(), $before_classes_list);

                foreach ($added_classes as $added_class) {
                    if (preg_match($this->controllerInModuleClassPattern, $added_class, $matches)) {

                        // Повторная проверка имени модуля, чтобы исключить из генерации не связаные контроллеры
                        if ($this->moduleNamePattern !== null && !preg_match($this->moduleNamePattern, $matches['moduleId']))
                            continue;

                        $module_id = str_replace('_', '.', $matches['moduleId']);

                        // Обработка псевдо-вложенных контроллеров - перевод CamelCase в путь camel/case
                        preg_match_all('~[A-Z][a-z]+~', $matches['controller'], $uriParts);
                        $controller_actions = $this->generateClassMethodsList($added_class, $this->actionAsControllerMethodPattern);

                        if (!empty($controller_actions)) {
                            $total_actions += count($controller_actions);
                            $controllers_list[$module_id][$added_class] = [
                                'moduleId' => $module_id,
                                'controllerId' => implode('/', array_map('strtolower', $uriParts[0])),
                                'actions' => $controller_actions,
                            ];
                        }
                    }
                }
            }
        }

        array_walk($controllers_list, function (&$added_classes, $module_id) {
            ksort($added_classes, SORT_NATURAL);
        });

        ksort($controllers_list, SORT_NATURAL);

        return [$total_actions, $controllers_list];
    }

    /**
     * @param string $class
     * @param string $methodPattern
     * @return array
     * @throws \ReflectionException
     */
    public function generateClassMethodsList(string $class, string $methodPattern): array
    {
        $actions = [];

        $class_reflection = ReflectionsCollection::getClass($class);
        foreach ($class_reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method_reflection) {
            if (!preg_match($methodPattern, $method_reflection->getName(), $matches)) {
                continue;
            }
            $action_uri = strtolower(substr($matches['action'], 0, 1)).substr($matches['action'], 1);

            $doc_comment = $method_reflection->getDocComment();

            if ($doc_comment === false) {
                $this->notice('Method "'.$action_uri.'" of '
                    .$method_reflection->getDeclaringClass()->getName()
                    .' has no doc-block at all', self::NOTICE_WARNING);
                continue;
            }

            $actions[$action_uri] = $method_reflection->getName();
        }

        ksort($actions, SORT_NATURAL);

        return $actions;
    }

    /**
     * @param $directory
     */
    protected function initializeYiiAutoloader($directory)
    {
        require_once $directory.'/vendor/yiisoft/yii2/Yii.php';
        Yii::setAlias('@app', $directory);
    }

    /**
     * @param string $moduleId
     * @return Specification
     */
    protected function newSpecification(string $moduleId): Specification
    {
        $specification = new Specification();
        $specification->title = 'API Example';
        $specification->version = $moduleId;
        $specification->description = 'API Example version '.$moduleId;

        foreach ($this->servers as $server_url => $server_description) {
            $specification->servers[] = new Server([
                'url' => $server_url.$moduleId.'/',
                'description' => $server_description,
            ]);
        }

        return $specification;
    }

    /**
     * @param Specification $specification
     * @param string $authScheme
     * @return bool
     */
    protected function ensureSecuritySchemeAdded(Specification $specification, string $authScheme)
    {
        foreach ($specification->securitySchemes as $securityScheme) {
            if ($securityScheme->id === $authScheme) {
                return true;
            }
        }

        if (isset($this->securitySchemes[$authScheme])) {
            $scheme = $this->securitySchemes[$authScheme];
            $scheme['id'] = $authScheme;
            $specification->securitySchemes[] = new ApiKeySecurityScheme($scheme);
            return true;
        }

        return false;
    }
}
