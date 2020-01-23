<?php
namespace wapmorgan\OpenApiGenerator\Scraper;

use app\components\BaseJsonController;
use wapmorgan\OpenApiGenerator\Scraper\Result\ScrapeResult;
use wapmorgan\OpenApiGenerator\Scraper\Result\ScrapeResultController;
use wapmorgan\OpenApiGenerator\Scraper\Result\ScrapeResultControllerAction;

class Yii2Scraper extends DefaultScrapper
{
    public $excludedModules = [];

    public $scrapeModules = true;
    public $scrapeApplication = false;

    public $moduleNamePattern = null;
    public $controllerInModuleClassPattern = '~^app\\\\modules\\\\(?<moduleId>[a-z0-9_]+)\\\\controllers\\\\(?<controller>[a-z0-9_]+)Controller$~i';
    public $actionAsControllerMethodPattern = '~^action(?<action>[A-Z][a-z0-9_]+)$~i';

    /**
     * @inheritDoc
     */
    public function scrape(): ScrapeResult
    {
        $directory = getcwd();
        require_once $directory.'/vendor/yiisoft/yii2/Yii.php';

        $directories = $this->getControllerDirectories($directory);
        list($total_actions, $controllers) = $this->getActionsList($directories);

        ksort($controllers_list, SORT_NATURAL);

        $result = new ScrapeResult();
        foreach ($controllers as $module_id => $module_controllers) {
            foreach ($module_controllers as $controller_class => $controller_configuration) {
                /** @var BaseJsonController $controller_instance */
                $controller_result = new ScrapeResultController();
                $controller_result->moduleId = $module_id;
                $controller_result->controllerId = $controller_configuration['controllerId'];
                $controller_result->class = $controller_class;

                foreach ($controller_configuration['actions'] as $controller_action_id => $controller_action_method) {
                    $controller_action_result = new ScrapeResultControllerAction();
                    $controller_action_result->controller = $controller_result;
                    $controller_action_result->actionType = ScrapeResultControllerAction::CONTROLLER_METHOD;
                    $controller_action_result->actionId = $controller_action_id;
                    $controller_action_result->actionControllerMethod = $controller_action_method;
                    $controller_result->actions[] = $controller_action_result;
                }
            }

            $result->controllers[] = $controller_result;
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

                if ($this->moduleNamePattern !== null && !preg_match($this->moduleNamePattern, $module_name)) {
                    echo 'Skipping '.$module_name.PHP_EOL;
                    continue;
                }

                if (in_array($module_name, $this->excludedModules, true))
                    continue;

                $directories[] = $module_dir.'/controllers';
            }
        }
        sort($directories);
        return $directories;
    }

    /**
     * @param array $directories
     * @return array
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

//                $this->addImports($added_classes);
            }
        }

        array_walk($controllers_list, function (&$added_classes, $module_id) {
            ksort($added_classes, SORT_NATURAL);
        });

        ksort($controllers_list, SORT_NATURAL);

        return [$total_actions, $controllers_list];
    }

    public function generateClassMethodsList($added_class, string $actionAsControllerMethodPattern)
    {
    }
}
