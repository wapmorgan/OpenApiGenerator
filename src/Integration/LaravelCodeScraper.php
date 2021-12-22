<?php
namespace wapmorgan\OpenApiGenerator\Integration;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
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

    /**
     * @return string[]
     */
    public function getServers(): array
    {
        return [
            'localhost' => 'http://localhost:8080',
        ];
    }

    /**
     * @return PathResultWrapper|null
     */
    public function getPathResultWrapper(): ?PathResultWrapper
    {
        return null;
    }

    public function getTitle()
    {
        return 'API Specification';
    }

    public function scrape(): Result
    {
        $cwd = getcwd();
        $app = $this->getApp($cwd);
        $routes = \Illuminate\Support\Facades\Route::getRoutes()->getRoutes();

        $result = new Result();
        $result->specifications[0] = new Specification();
        $result->specifications[0]->version = 'api';
        $result->specifications[0]->title = $this->getTitle();

        foreach ($this->getServers() as $serverUrl) {
            $result->specifications[0]->servers[] = new Server(['url' => $serverUrl]);
        }

        $path_wrapper = $this->getPathResultWrapper();

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
//            $endpoint->result = 'null';
            $result->specifications[0]->endpoints[] = $endpoint;
        }

        return $result;
    }
}
