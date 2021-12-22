<?php
namespace wapmorgan\OpenApiGenerator;

use wapmorgan\OpenApiGenerator\Integration\LaravelCodeScraper;
use wapmorgan\OpenApiGenerator\Integration\SlimCodeScraper;
use wapmorgan\OpenApiGenerator\Integration\Yii2CodeScraper;
use wapmorgan\OpenApiGenerator\Scraper\Result;

abstract class ScraperSkeleton extends ErrorableObject
{
    public $specificationPattern = '.+';
    public $specificationAntiPattern = false;

    /**
     * Should return list of controllers
     * @return \wapmorgan\OpenApiGenerator\Scraper\Result
     */
    abstract public function scrape(): Result;

    /**
     * @param string $doc
     * @param string $parameter
     * @param null $defaultValue
     * @return string|null
     */
    protected function getDocParameter(string $doc, string $parameter, $defaultValue = null)
    {
        if (empty($doc))
            return $defaultValue;

        $doc = explode("\n", $doc);
        foreach ($doc as $line) {
            $line = trim($line, " *\t");
            if (strpos($line, '@'.$parameter) === 0) {
                return trim(substr($line, strlen($parameter) + 1));
            }
        }

        return $defaultValue;
    }

    public static function getAllDefaultScrapers()
    {
        return [
            'yii2' => Yii2CodeScraper::class,
            'slim' => SlimCodeScraper::class,
            'laravel' => LaravelCodeScraper::class,
        ];
    }
}
