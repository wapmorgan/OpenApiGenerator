<?php
namespace wapmorgan\OpenApiGenerator\Scraper;

use wapmorgan\OpenApiGenerator\InitableObject;

class Server extends InitableObject
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
