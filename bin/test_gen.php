#!/usr/bin/env php
<?php

use wapmorgan\OpenApiGenerator\Generator\Result\GeneratorResult;
use wapmorgan\OpenApiGenerator\Scraper\Result\ScrapeResultController;
use wapmorgan\OpenApiGenerator\Scraper\Result\ScrapeResultControllerAction;

require dirname(__DIR__).'/../../autoload.php';

$scraper = new \wapmorgan\OpenApiGenerator\Scraper\Yii2Scraper();
$scraper->moduleNamePattern = '~^v2_14_0$~';
$scraper->setOnNoticeCallback(function (string $message, int $level) {
    switch ($level) {
        case \wapmorgan\OpenApiGenerator\Scraper\Yii2Scraper::NOTICE_INFO:
        case \wapmorgan\OpenApiGenerator\Scraper\Yii2Scraper::NOTICE_WARNING:
            echo $message.PHP_EOL;
            break;
    }
});
$scrape_result = $scraper->scrape();

$generator = new \wapmorgan\OpenApiGenerator\Generator\DefaultGenerator();
$generator->setOnStartCallback(function ($actions) {echo 'Starting.... Total action: '.$actions.PHP_EOL;});
$generator->setOnEndCallback(function (GeneratorResult $result) {echo 'Finished....'.PHP_EOL;});
$generator->setOnControllerActionStartCallback(function (ScrapeResultController $controller, ScrapeResultControllerAction $action) {
    echo 'Working on '.$controller->moduleId.'/'.$controller->controllerId.'/'.$action->actionId.'...';
});
$generator->setOnControllerActionEndCallback(function (ScrapeResultController $controller, ScrapeResultControllerAction $action) {
    echo PHP_EOL;
});
$result = $generator->generate($scrape_result);

foreach ($result->specifications as $specification) {
    var_dump($specification->specification->toYaml());
}
