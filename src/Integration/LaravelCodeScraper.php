<?php
namespace wapmorgan\OpenApiGenerator\Integration;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use wapmorgan\OpenApiGenerator\Extractor\LaravelFormRequestExtractor;
use wapmorgan\OpenApiGenerator\Scraper\Endpoint;
use wapmorgan\OpenApiGenerator\Scraper\Server;
use wapmorgan\OpenApiGenerator\Scraper\Specification;
use wapmorgan\OpenApiGenerator\ScraperSkeleton;

class LaravelCodeScraper extends ScraperSkeleton
{
    /**
     * @return Application|null
     */
    public function getApp($workDir): ?Application
    {
        if (!file_exists($workDir.'/bootstrap/app.php')) {
            return null;
        }

        /** @var Application $app */
        $app = require_once $workDir.'/bootstrap/app.php';
        /** @var Kernel $kernel */
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        return $app;
    }

    public function getTitle()
    {
        return 'API Specification';
    }

    public function scrape(string $folder): array
    {
        $app = $this->getApp($folder);
        if ($app === null) {
            throw new \Exception('Could not start Application: not found starting script "/bootstrap/app.php"!');
        }
        $routes = \Illuminate\Support\Facades\Route::getRoutes()->getRoutes();
        $this->notice('Got ' . count($routes) . ' method(s) from laravel Route', self::NOTICE_IMPORTANT);

        $result = [];

        $result[0] = new Specification();
        $result[0]->version = static::$specificationVersion;
        $result[0]->title = static::$specificationTitle;
        $result[0]->description = static::$specificationDescription;

        foreach ($this->getServers() as $serverUrl => $serverDescription) {
            $result[0]->servers[] = new Server(['url' => $serverUrl, 'description' => $serverDescription]);
        }
        $path_wrapper = $this->getDefaultResponseWrapper();

        foreach ($routes as $route) {
            $endpoint = new Endpoint();
            $pattern = '/'.ltrim($route->uri(), '/');
            $endpoint->id = $pattern;
            $endpoint->httpMethod = strtolower(current($route->methods()));

            if (isset($route->action['controller'])) {
                $callable = $route->action['controller'];
                if (strpos($callable, '@') && (list($controller, $action) = explode('@', $callable))
                    && class_exists($controller) && is_a($controller, \Illuminate\Routing\Controller::class, true)) {
                    $endpoint->callback = [$controller, $action];
                }
            } else if (isset($route->action['uses']) && is_callable($route->action['uses'])) {
                $endpoint->callback = $route->action['uses'];
            }
            if (substr_count($pattern, '/') > 1) {
                $endpoint->tags[] = substr($pattern, 1, strpos($pattern, '/', 1) - 1);
            }
            $endpoint->resultWrapper = $path_wrapper;

            $result[0]->endpoints[] = $endpoint;
        }

        return $result;
    }

    public function getArgumentExtractors(): array
    {
        return [
            FormRequest::class => LaravelFormRequestExtractor::class,
        ];
    }

    public function getClassDescribingOptions(): array
    {
        return array_merge(parent::getClassDescribingOptions(), [
            \Symfony\Component\HttpFoundation\Response::class => [],
        ]);
    }
}
