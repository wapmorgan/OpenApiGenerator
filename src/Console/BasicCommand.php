<?php
namespace wapmorgan\OpenApiGenerator\Console;

use Symfony\Component\Console\Command\Command;
use wapmorgan\OpenApiGenerator\Scraper\DefaultScraper;

abstract class BasicCommand extends Command
{
    /**
     * @param string $scraperType
     * @return DefaultScraper
     */
    protected function createScraper(string $scraperType): DefaultScraper
    {
        if (class_exists($scraperType)) {
            return new $scraperType;
        }

        $file = realpath(getcwd().'/'.$scraperType);
        if (file_exists($file)) {
            $classes_before = get_declared_classes();
            require_once $file;
            $new_classes = array_diff(get_declared_classes(), $classes_before);
            foreach ($new_classes as $new_class) {
                if (is_subclass_of($new_class, DefaultScraper::class)) {
                    return new $new_class;
                }
            }
        }

        throw new \InvalidArgumentException('Invalid scraper: '.$scraperType);
    }
}