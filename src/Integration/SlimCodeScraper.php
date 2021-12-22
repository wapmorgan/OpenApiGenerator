<?php
namespace wapmorgan\OpenApiGenerator\Integration;

use App\Application\Actions\Action;
use Slim\App;
use wapmorgan\OpenApiGenerator\Scraper\Endpoint;
use wapmorgan\OpenApiGenerator\Scraper\PathResultWrapper;
use wapmorgan\OpenApiGenerator\Scraper\Result;
use wapmorgan\OpenApiGenerator\Scraper\Server;
use wapmorgan\OpenApiGenerator\Scraper\Specification;
use wapmorgan\OpenApiGenerator\ScraperSkeleton;

abstract class SlimCodeScraper extends ScraperSkeleton
{
    /**
     * @return App
     */
    abstract public function getApp(): App;

    /**
     * @return string[]
     */
    abstract public function getServers(): array;

    /**
     * @return PathResultWrapper|null
     */
    abstract public function getPathResultWrapper(): ?PathResultWrapper;

    public function getTitle()
    {
        return 'API Specification';
    }

    public function scrape(): Result
    {
        $app = $this->getApp();
        $routes = $app->getRouteCollector()->getRoutes();

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
            $pattern = $route->getPattern();
            $endpoint->id = $pattern;
            $endpoint->httpMethod = strtolower(current($route->getMethods()));

            $callable = $route->getCallable();
            if (is_string($callable) && class_exists($callable) && is_a($callable, Action::class, true)) {
                $endpoint->callback = [$callable, 'action'];
            }

            if (substr_count($pattern, '/') > 1) {
                $endpoint->tags[] = substr($pattern, 1, strpos($pattern, '/', 1) - 1);
            }

            $endpoint->resultWrapper = $path_wrapper;
            $endpoint->result = 'null';

            $result->specifications[0]->endpoints[] = $endpoint;
        }

        return $result;
    }
}
