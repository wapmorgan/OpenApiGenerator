<?php
namespace wapmorgan\OpenApiGenerator\Integration\Yii2;

use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\Generator\DefaultGenerator;
use wapmorgan\OpenApiGenerator\Scraper\DefaultScraper;
use wapmorgan\OpenApiGenerator\Scraper\Result\Endpoint;
use wapmorgan\OpenApiGenerator\Scraper\Result\Specification;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\ActiveRecord;
use yii\helpers\Console;

/**
 * Generator of OpenApi configuration for API.
 * @package wapmorgan\OpenApiGenerator\Command\yii2
 */
class GeneratorController extends Controller
{
    public $scraper = DefaultScraper::class;

    /**
     * @var bool
     */
    public $verbose;

    /**
     * @var bool
     */
    public $trace = false;

    /**
     * @var string|null Default output directory
     */
    public $outputDirectory = '@app/web/api';

    /**
     * @var string Format of result: json or yaml
     */
    public $outputFormat = 'json';

    /**
     * @var string Pattern of file when saving. First %s - module Id, second %s - format type.
     */
    public $outputFilePattern = '%s.%s';

    /**
     * @var string|null Global property for Generation process
     */
    protected $currentPath;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'verbose',
            'trace',
            'outputDirectory',
            'outputFormat',
            'outputFilePattern',
        ]);
    }

    static protected $noticeLevelColors = [
        DefaultGenerator::NOTICE_SUCCESS => [Console::FG_GREEN],
        DefaultGenerator::NOTICE_IMPORTANT => [Console::BG_BLUE, Console::FG_GREY],
        DefaultGenerator::NOTICE_INFO => [Console::BG_GREY, Console::FG_BLACK],
        DefaultGenerator::NOTICE_WARNING => [Console::FG_RED],
        DefaultGenerator::NOTICE_ERROR => [Console::BG_YELLOW, Console::FG_RED],
        'trace' => [Console::BG_BLACK, Console::FG_GREY],
    ];

    /**
     * @param null|string $outputDirectory Directory where yaml/json files should be saved
     * @throws \ReflectionException
     */
    public function actionIndex($outputDirectory = null): int
    {
        $scraper = $this->buildScraper();
        $generator = $this->buildGenerator();

        $result = $generator->generate($scraper);

        $directory = Yii::getAlias($outputDirectory ?: $this->outputDirectory);
        if (!is_dir($directory)) {
            mkdir ($directory, 0777, true);
        } else if (is_writable($directory)) {
            chmod($directory, 0777);
        }

        foreach ($result->specifications as $result_specification) {
            $data = $this->outputFormat === 'json'
                ? $result_specification->specification->toJson()
                : $result_specification->specification->toYaml();
            $data_size = strlen($data);

            $file_name = $directory .'/'
                .sprintf($this->outputFilePattern, $result_specification->id, $this->outputFormat);

            self::output('Saving '.$this->outputFormat.' for '.$result_specification->id.' as '.$file_name, DefaultGenerator::NOTICE_IMPORTANT);

            if (($written = file_put_contents($file_name, $data)) !== $data_size) {
                throw new \RuntimeException('Written '.$written.' byte(s) out of '.$data_size);
            }
        }

        return ExitCode::OK;
    }

    /**
     * @return DefaultGenerator
     */
    protected function buildGenerator(): DefaultGenerator
    {
        $generator = $this->constructGenerator();
        $this->setupGeneratorOutput($generator);

        return $generator;
    }

    /**
     * @return DefaultGenerator
     */
    protected function constructGenerator(): DefaultGenerator
    {
        $generator = new DefaultGenerator();
        $this->setMessagesCallbacks($generator);
        $generator->getClassDescriber()->setClassDescribingOptions(ActiveRecord::class, []);
        return $generator;
    }

    public function setMessagesCallbacks(ErrorableObject $emitter)
    {
        $emitter->setOnNoticeCallback([$this, 'onNoticeCallback']);
        $emitter->setOnTraceCallback([$this, 'onTraceCallback']);
    }

    public function onNoticeCallback($message, $level)
    {
        self::output(
            ($this->currentPath !== null ? $this->currentPath.': ' : null).$message,
            $level
        );
    }

    public function onTraceCallback($message)
    {
        if ($this->trace)
            self::output(
                ($this->currentPath !== null ? $this->currentPath.': ' : null).$message, 'trace'
            );
    }

    protected $done = 0;

    protected $total = 0;

    /**
     * @var string|null Global property for Generation process
     */
    protected $currentPath;


    /**
     * @param DefaultGenerator $generator
     * @return void
     */
    protected function setupGeneratorOutput(DefaultGenerator $generator): void
    {
        $generator->setOnSpecificationStartCallback([$this, 'onSpecificationStartCallback']);
        $generator->setOnSpecificationEndCallback([$this, 'onSpecificationEndCallback']);
        $generator->setOnPathStartCallback([$this, 'onPathStartCallback']);
        $generator->setOnPathEndCallback([$this, 'onPathEndCallback']);
    }

    public function onSpecificationStartCallback(Specification $spec)
    {
        $this->done = 0;
        $this->total = count($spec->endpoints);
        Console::startProgress(0, $this->total, $spec->title);
    }

    public function onSpecificationEndCallback(Specification $spec)
    {
        $this->total = 0;
        Console::endProgress();
    }

    public function onPathStartCallback(Endpoint $path, Specification $specification)
    {
        $this->currentPath = $specification->version.$path->id;
    }

    public function onPathEndCallback(Endpoint $path, Specification $specification)
    {
        $this->currentPath = null;
        $this->done++;
        Console::updateProgress($this->done, $this->total, $specification->version . ($this->verbose ? $path->id : null));
    }

    /**
     * @return DefaultScraper
     */
    protected function buildScraper(): DefaultScraper
    {
        $scraper = new $this->scraper();
        $this->setMessagesCallbacks($scraper);
        return $scraper;
    }

    public static function output($message, $level): void
    {
        Console::output(Console::ansiFormat($message, static::$noticeLevelColors[$level]));
    }
}
