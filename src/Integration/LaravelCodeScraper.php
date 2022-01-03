<?php
namespace wapmorgan\OpenApiGenerator\Integration;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\In;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\Schema;
use wapmorgan\OpenApiGenerator\Extractor\LaravelFormRequestExtractor;
use wapmorgan\OpenApiGenerator\Scraper\Endpoint;
use wapmorgan\OpenApiGenerator\Scraper\PathResultWrapper;
use wapmorgan\OpenApiGenerator\Scraper\Result;
use wapmorgan\OpenApiGenerator\Scraper\Server;
use wapmorgan\OpenApiGenerator\Scraper\Specification;
use wapmorgan\OpenApiGenerator\ScraperSkeleton;

class LaravelCodeScraper extends ScraperSkeleton
{
    /**
     * @return Application
     */
    public function getApp($workDir): Application
    {
        if (!file_exists($workDir.'/bootstrap/app.php'))
            return false;

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

    public function scrape(): array
    {
        $cwd = getcwd();
        $app = $this->getApp($cwd);
        $routes = \Illuminate\Support\Facades\Route::getRoutes()->getRoutes();

        $result = [];

        $result[0] = new Specification();
        $result[0]->version = 'default';
        $result[0]->title = $this->specificationTitle;
        $result[0]->description = $this->specificationDescription;

        foreach ($this->getServers() as $serverUrl => $serverDescription) {
            $result[0]->servers[] = new Server(['url' => $serverUrl, 'description' => $serverDescription]);
        }
        $path_wrapper = $this->getDefaultResponseWrapper();

        foreach ($routes as $route) {
            $endpoint = new Endpoint();
            $pattern = '/'.ltrim($route->uri(), '/');
            $endpoint->id = $pattern;
            $endpoint->httpMethod = strtolower(current($route->methods()));
            $callable = $route->action['controller'];
            if (strpos($callable, '@') && (list($controller, $action) = explode('@', $callable))
                && class_exists($controller) && is_a($controller, \Illuminate\Routing\Controller::class, true)) {
                $endpoint->callback = [$controller, $action];
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
}
