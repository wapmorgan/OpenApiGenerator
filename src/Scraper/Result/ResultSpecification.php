<?php
namespace wapmorgan\OpenApiGenerator\Scraper\Result;

class ResultSpecification
{
    /**
     * @var ResultTag[]
     */
    public $tags = [];

    /**
     * @var ResultPath[]
     */
    public $paths = [];

    /**
     * @var ResultServer[] List of servers
     */
    public $servers = [];

    /**
     * @var ResultSecurityScheme[] List of security schemes
     */
    public $securitySchemes = [];

    /**
     * @var string Specification description
     */
    public $description;

    /**
     * @var string|null Specification version
     */
    public $version;

    /**
     * @var int Number of paths
     */
    public $totalPaths = 0;
}
