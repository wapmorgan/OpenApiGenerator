<?php
namespace wapmorgan\OpenApiGenerator\Scraper\SecurityScheme;

class OpenIdConnectSecurityScheme extends \wapmorgan\OpenApiGenerator\InitableObject
{
    /**
     * @var string ID of security scheme
     */
    public $id;

    /**
     * @var string URL of the discovery endpoint
     */
    public $openIdConnectUrl;
}
