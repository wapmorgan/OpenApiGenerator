<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

use wapmorgan\OpenApiGenerator\Initable;

class ResultServer extends Initable
{
    /**
     * @var string URL of server
     */
    public $url;

    /**
     * @var string|null Description of server
     */
    public $description;
}