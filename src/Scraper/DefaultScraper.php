<?php
namespace wapmorgan\OpenApiGenerator\Scraper;

use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\Scraper\Result\Result;

abstract class DefaultScraper extends ErrorableObject
{
    /**
     * Should return list of controllers
     * @return Result
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
}
