#!/usr/bin/env php
<?php
require dirname(__DIR__).'/../../autoload.php';

$scraper = new \wapmorgan\OpenApiGenerator\Scraper\Yii2Scraper();
var_dump($scraper->scrape());
