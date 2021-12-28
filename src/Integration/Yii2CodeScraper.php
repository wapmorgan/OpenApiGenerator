<?php
namespace wapmorgan\OpenApiGenerator\Integration;

use ReflectionMethod;
use wapmorgan\OpenApiGenerator\ReflectionsCollection;
use wapmorgan\OpenApiGenerator\Scraper\Endpoint;
use wapmorgan\OpenApiGenerator\Scraper\Server;
use wapmorgan\OpenApiGenerator\Scraper\Specification;
use wapmorgan\OpenApiGenerator\Scraper\Tag;
use wapmorgan\OpenApiGenerator\ScraperSkeleton;
use Yii;
use yii\web\Controller;

class Yii2CodeScraper extends ScraperSkeleton
{
    public $scrapeModules = true;
    public $scrapeApplication = true;
    public $scanControllerInlineActions = true;
    public $scanControllerExternalActions = true;
    /** @var array<string>string[] Pairs of string-string to be replaced from module folder name and module path in URL */
    public $replacesForModulePath = [];

    public $controllerInModuleClassPattern =
        '~^app\\\\modules\\\\(?<moduleId>[a-z0-9_]+)\\\\controllers\\\\(?<controller>[a-z0-9_]+)Controller$~i';
    public $actionAsControllerMethodPattern = '~^action(?<action>[A-Z][a-z0-9_]+)$~i';

    /**
     * @inheritDoc
     * @throws \ReflectionException
     */
    public function scrape(): array
    {
        if (!class_exists('\\Yii', false)) {
            $directory = dirname(__FILE__, 6);
            $this->initializeYiiAutoloader($directory);
        } else {
            $directory = Yii::getAlias('@app');
        }

        list($total_actions, $controllers) = $this->collectActions($directory);
        $this->notice('Retrieved '
                      . count(array_keys($controllers))
                      . ' module(s) with ' . $total_actions . ' action(s)', self::NOTICE_IMPORTANT);
        ksort($controllers, SORT_NATURAL);

        $default_wrapper = $this->getDefaultResponseWrapper();
        $result = [];

        foreach ($controllers as $module_id => $module_controllers) {
            $module_class = $this->getModuleClass($module_id);
            if ($module_class !== null) {
                $module_reflection = ReflectionsCollection::getClass($module_class);
                $module_doc = $module_reflection->getDocComment();
                $module_description = $this->getDocParameter($module_doc, 'description');
                $module_docs = $this->getDocParameter($module_doc, 'docs');
                $module_alias = $this->getDocParameter($module_doc, 'alias');
            }

            $specification = $this->newSpecification(
                $module_id,
                 $module_alias ?? $module_id,
                 $module_description ?? null,
                $module_docs ?? null);

            foreach ($module_controllers as $controller_class => $controller_configuration) {
                $controller_reflection = ReflectionsCollection::getClass($controller_class);

                $controller_doc = $controller_reflection->getDocComment();
                $controller_title = $this->getDocParameter($controller_doc, 'title', '');
                $controller_description = $this->getDocParameter($controller_doc, 'description', '');
                $controller_docs = $this->getDocParameter($controller_doc, 'docs', '');
                $controller_tag = !empty($controller_title)
                    ? '('.$controller_configuration['controllerId'].') '.$controller_title
                    : $controller_configuration['controllerId'];
                $specification->tags[] = new Tag([
                    'name' => $controller_tag,
                    'description' => $controller_description,
                    'externalDocs' => $controller_docs,
                ]);

                foreach ($controller_configuration['actions'] as $controller_action_id => $controller_action_method) {
                    try {
                        $path = new Endpoint();
                        $path->id =
                            ($controller_configuration['controllerId'] !== 'default'
                                ? '/' . $controller_configuration['controllerId']
                                : null) // hide default controller path
                            . '/' . $controller_action_id;
                        $path->tags[] = $controller_tag;
                        $path->resultWrapper = $default_wrapper;

                        $action_reflection = $this->parseActionInController(
                            $controller_class,
                            $controller_action_method,
                            $path
                        );
                        $action_doc = $action_reflection->getDocComment();

                        // check for @auth tag
                        if (
                            !empty($action_auth = $this->getDocParameter($action_doc, 'auth', ''))
                            || $this->checkDefaultActionAuth(
                                $controller_reflection,
                                $controller_action_id,
                                $path
                            )
                        ) {
                            if (isset($action_auth) && !empty($action_auth)) {
                                $this->ensureSecuritySchemeAdded($specification, $action_auth);
                                $path->securitySchemes[] = $action_auth;
                            } else {
                                foreach ((array)$this->defaultSecurityScheme as $defaultSecurityScheme) {
                                    $this->ensureSecuritySchemeAdded($specification, $defaultSecurityScheme);
                                    $path->securitySchemes[] = $defaultSecurityScheme;
                                }
                            }
                        }

                        // check for @alias tag
                        if (!empty($action_endpoint = $this->getDocParameter($action_doc, 'alias', ''))) {
                            $path->id = $action_endpoint;
                        }

                        $specification->endpoints[] = $path;
                    } catch (\Exception $e) {
                        $this->error('Error during working on '.($controller_configuration['controllerId'] !== 'default'
                                       ? '/' . $controller_configuration['controllerId']
                                       : null) // hide default controller path
                                   . '/' . $controller_action_id.PHP_EOL
                                   .$e->getMessage().' in '.$e->getFile().':'.$e->getLine()
                                   .$e->getTraceAsString());
                    }
                }
            }

            $result[] = $specification;
        }

        return $result;
    }

    /**
     * @param array $directories
     * @return array
     * @throws \ReflectionException
     */
    public function getActionsList(array $directories): array
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
                        $module_id = strtr($matches['moduleId'], $this->replacesForModulePath);
                        if (!$this->checkModule($module_id)) {
                            $this->notice('Skipping '.$module_id.' because of check',
                                          self::NOTICE_INFO);
                            continue;
                        }

                        if (!empty($this->specificationPattern) && !preg_match('~^'.$this->specificationPattern.'$~i', $matches['moduleId'])) {
//                            $this->notice('Skipping ' . $matches['moduleId'], self::NOTICE_INFO);
                            continue;
                        }

                        if (!empty($this->specificationAntiPattern) && preg_match('~^'.$this->specificationAntiPattern.'$~i', $matches['moduleId'])) {
//                            $this->notice('Skipping ' . $matches['moduleId'], self::NOTICE_INFO);
                            continue;
                        }

                        // Обработка псевдо-вложенных контроллеров - перевод CamelCase в путь camel/case
                        preg_match_all('~[A-Z][a-z]+~', $matches['controller'], $uriParts);
                        $controller_actions = $this->generateClassMethodsList($added_class);

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
     * @return array
     * @throws \ReflectionException
     */
    public function generateClassMethodsList(string $class): array
    {
        $actions = [];
        $class_reflection = ReflectionsCollection::getClass($class);

        if ($this->scanControllerInlineActions) {
            $this->appendControllerInlineActions($class_reflection, $actions);
        }
        // get external actions (from actions() method)
        if ($this->scanControllerExternalActions) {
            $this->appendControllerExternalActions($class_reflection, $actions);
        }

        ksort($actions, SORT_NATURAL);
        return $actions;
    }

    /**
     * @param $rootDirectory
     */
    protected function initializeYiiAutoloader($rootDirectory)
    {
        require_once $rootDirectory.'/vendor/yiisoft/yii2/Yii.php';
        Yii::setAlias('@app', $rootDirectory);
    }

    /**
     * @param string $moduleId
     * @param string $modulePath
     * @param string|null $moduleDescription
     * @param string|null $moduleDocs
     * @return Specification
     */
    protected function newSpecification(
        string $moduleId,
        string $modulePath,
        ?string $moduleDescription,
        ?string $moduleDocs
    ): Specification
    {
        $specification = new Specification();
        $specification->title = sprintf($this->specificationTitle, $moduleId);
        $specification->version = $moduleId;
        $specification->description = $moduleDescription ?? sprintf($this->specificationDescription, $moduleId);
        $specification->externalDocs = $moduleDocs;

        foreach ($this->servers as $server_url => $server_description) {
            $specification->servers[] = new Server([
               'url' => $server_url . $modulePath . '/',
               'description' => $server_description,
            ]);
        }

        return $specification;
    }

    /**
     * @param string $rootDirectory
     * @return array
     * @throws \ReflectionException
     */
    protected function collectActions(string $rootDirectory): array
    {
        $controller_directories = [];

        if ($this->scrapeApplication) {
            $this->appendApplicationControllers($rootDirectory, $controller_directories);
        }

        if ($this->scrapeModules) {
            $this->appendModulesControllers($rootDirectory, $controller_directories);
        }

        return $this->getActionsList($controller_directories);
    }

    /**
     * @param string $rootDirectory
     * @param array $controllerDirectories
     * @return void
     */
    protected function appendApplicationControllers(string $rootDirectory, array &$controllerDirectories): void
    {
        if (is_dir($controllers_dir = $rootDirectory . '/controllers')) {
            $controllerDirectories[] = $controllers_dir;
        }
    }

    /**
     * @param string $rootDirectory
     * @param array $controllerDirectories
     * @return void
     */
    protected function appendModulesControllers(string $rootDirectory, array &$controllerDirectories): void
    {
        if (is_dir($modules_dir = $rootDirectory . '/modules')) {
            foreach (glob($modules_dir . '/*', GLOB_ONLYDIR) as $module_dir) {
                if (!is_dir($module_dir . '/controllers')) {
                    continue;
                }

                $module_name = basename($module_dir);

                if (!empty($this->specificationPattern) && !preg_match('~^'.$this->specificationPattern.'$~i', $module_name)) {
                    $this->notice('Skipping ' . $module_name, self::NOTICE_INFO);
                    continue;
                }

                if (!empty($this->specificationAntiPattern) && preg_match('~^'.$this->specificationAntiPattern.'$~i', $module_name)) {
                    continue;
                }

                if (!$this->checkModule($module_name)) {
                    continue;
                }

                $controllerDirectories[] = $module_dir . '/controllers';
            }
        }
    }

    /**
     * @param \ReflectionClass $class_reflection
     * @param array $actions
     * @return void
     */
    protected function appendControllerInlineActions(\ReflectionClass $class_reflection, array &$actions): void
    {
        foreach ($class_reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method_reflection) {
            if (!preg_match($this->actionAsControllerMethodPattern, $method_reflection->getName(), $matches)) {
                continue;
            }
            $action_uri = strtolower(substr($matches['action'], 0, 1)) . substr($matches['action'], 1);

            $doc_comment = $method_reflection->getDocComment();

            if ($doc_comment === false) {
                $this->notice(
                    'Method "' . $action_uri . '" of '
                    . $method_reflection->getDeclaringClass()->getName()
                    . ' has no doc-block at all',
                    self::NOTICE_WARNING
                );
                continue;
            }

            $actions[$action_uri] = $method_reflection->getName();
        }
    }

    /**
     * @param \ReflectionClass $classReflection
     * @param array $actions
     * @return void
     * @throws \ReflectionException
     */
    protected function appendControllerExternalActions(\ReflectionClass $classReflection, array &$actions)
    {
        if ($classReflection->isSubclassOf(Controller::class)) {
            $external_actions = $classReflection->newInstanceWithoutConstructor()->actions();

            if (is_array($external_actions) && is_array($external_actions)) {
                foreach ($external_actions as $external_action_uri => $external_action_config) {
                    if (!is_string($external_action_config)) {
                        if (!isset($external_action_config['class'])) {
                            $this->notice(
                                'Class-handler for ' . $external_action_uri . ' is not defined!',
                                self::NOTICE_ERROR
                            );
                            continue;
                        }
                        $external_action_class = $external_action_config['class'];
                    } else {
                        $external_action_class = $external_action_config;
                    }

                    if (!class_exists($external_action_class)) {
                        $this->notice(
                            'Class-handler for action '
                            . $external_action_uri
                            . ' - ' . $external_action_class
                            . ' is not found',
                            self::NOTICE_ERROR
                        );
                        continue;
                    }

                    if (is_string($external_action_config)) {
                        $actions[$external_action_uri] = [$external_action_class, 'run'];
                    } else {
                        $actions[$external_action_uri] = [$external_action_class, 'run', $external_action_config];
                    }
                }
            }
        }
    }

    /**
     * @param string $moduleId
     * @return string|null
     */
    protected function getModuleClass(string $moduleId): ?string
    {
        $moduleId = strtr($moduleId, array_flip($this->replacesForModulePath));
        $namespace_prefix = '\\app\\modules\\';
        $class_name = $moduleId;
        if (substr($class_name, 0, 1) !== 'v') {
            $class_name = ucfirst($class_name);
        }

        if (class_exists($namespace_prefix . $moduleId . '\\' . $class_name . 'Module')) {
            return $namespace_prefix . $moduleId . '\\' . $class_name . 'Module';
        } elseif (class_exists($namespace_prefix . $moduleId . '\\Module')) {
            return $namespace_prefix . $moduleId . '\\Module';
        } elseif (class_exists($namespace_prefix . $moduleId . '\\' . $class_name)) {
            return $namespace_prefix . $moduleId . '\\' . $class_name;
        }

        return null;
    }

    /**
     * @param string $controllerClass
     * @param string|array $controllerActionMethod
     * @param Endpoint $path
     * @return ReflectionMethod
     * @throws \ReflectionException
     * @throws \yii\base\InvalidConfigException
     */
    protected function parseActionInController(
        string $controllerClass,
        $controllerActionMethod,
        Endpoint $path
    ): ReflectionMethod {
        if (is_string($controllerActionMethod)) {
            $path->callback = [$controllerClass, $controllerActionMethod];
            return ReflectionsCollection::getMethod(
                $controllerClass,
                $controllerActionMethod
            );
        } elseif (
            is_array($controllerActionMethod)
            && is_subclass_of($controllerActionMethod[0], \yii\base\Action::class)
        ) {
            $action_class_reflection = ReflectionsCollection::getClass($controllerActionMethod[0]);
            if (!$action_class_reflection->hasMethod($controllerActionMethod[1])) {
                $this->notice(
                    $controllerActionMethod[0]
                    . ' does not have '
                    . $controllerActionMethod[1]
                    . ' method',
                    self::NOTICE_ERROR
                );
                throw new \RuntimeException();
            }
            $path->callback = [$controllerActionMethod[0], $controllerActionMethod[1]];

            $this->checkForDifferentReturnType(
                $action_class_reflection,
                $controllerActionMethod,
                $path
            );

            return $action_class_reflection->getMethod($controllerActionMethod[1]);
        }
    }

    /**
     * @param string $moduleName
     * @return bool
     */
    protected function checkModule(string $moduleName): bool
    {
        return true;
    }

    /**
     * @param \ReflectionClass $actionClassReflection
     * @param array $controller_action_method
     * @param Endpoint $path
     * @return void
     */
    protected function checkForDifferentReturnType(
        \ReflectionClass $actionClassReflection,
        array $controller_action_method,
        Endpoint $path
    ): void {
    }

    /**
     * @param \ReflectionClass $controllerReflection
     * @param string $controllerActionId
     * @param Endpoint $path
     * @return bool
     */
    protected function checkDefaultActionAuth(
        \ReflectionClass $controllerReflection,
        string $controllerActionId,
        Endpoint $path
    ): bool
    {
        return false;
    }
}
