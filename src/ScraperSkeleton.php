<?php
namespace wapmorgan\OpenApiGenerator;

use wapmorgan\OpenApiGenerator\Integration\LaravelCodeScraper;
use wapmorgan\OpenApiGenerator\Integration\SlimCodeScraper;
use wapmorgan\OpenApiGenerator\Integration\Yii2CodeScraper;
use wapmorgan\OpenApiGenerator\Scraper\Result;
use wapmorgan\OpenApiGenerator\Scraper\SecurityScheme\ApiKeySecurityScheme;
use wapmorgan\OpenApiGenerator\Scraper\Specification;

abstract class ScraperSkeleton extends ErrorableObject
{
    public $specificationPattern = '.+';
    public $specificationAntiPattern = false;

    public $specificationTitle = 'API';
    public $specificationDescription = 'API version %s';

    public $servers = [
        'http://localhost:8080/' => 'Local server',
    ];

    public $defaultSecurityScheme = [];
    public $_securitySchemesCached;

    /**
     * @return ApiKeySecurityScheme[]
     */
    public function getAllSecuritySchemes()
    {
        return [
            'defaultAuth' => new ApiKeySecurityScheme([
                'type' => 'apiKey',
                'in'=> 'query',
                'name' => 'session_id',
                'description' => 'ID сессии',
            ]),
        ];
    }



    /**
     * @param Specification $specification
     * @param string $authScheme
     * @return bool
     */
    protected function ensureSecuritySchemeAdded(Specification $specification, string $authScheme): bool
    {
        if ($this->_securitySchemesCached === null) {
            $this->_securitySchemesCached = $this->getAllSecuritySchemes();
        }

        foreach ($specification->securitySchemes as $securityScheme) {
            if ($securityScheme->id === $authScheme) {
                return true;
            }
        }

        if (isset($this->_securitySchemesCached[$authScheme])) {
            $scheme = $this->_securitySchemesCached[$authScheme];
            $scheme->id = $authScheme;
            $specification->securitySchemes[] = $scheme;
            $this->notice(
                'Added auth schema "' . $authScheme . '" to specification "' . $specification->version . '"',
                self::NOTICE_INFO
            );
            return true;
        }

        $this->notice('Auth schema ' . $authScheme . ' is not defined', self::NOTICE_ERROR);
        return false;
    }

    /**
     * Should return list of controllers
     * @return array
     */
    abstract public function scrape(): array;

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

    /**
     * @return string[]
     */
    public static function getAllDefaultScrapers()
    {
        return [
            'yii2' => Yii2CodeScraper::class,
            'slim' => SlimCodeScraper::class,
            'laravel' => LaravelCodeScraper::class,
        ];
    }

    protected function getDefaultResponseWrapper()
    {
        return null;
    }
}
