<?php
namespace wapmorgan\OpenApiGenerator\Command\yii2;

use wapmorgan\OpenApiGenerator\Generator\DefaultGenerator;
use wapmorgan\OpenApiGenerator\Scraper\DefaultScrapper;
use wapmorgan\OpenApiGenerator\Scraper\Result\ResultPath;
use wapmorgan\OpenApiGenerator\Scraper\Result\ResultSpecification;
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
    public $scraper = DefaultScrapper::class;

    /**
     * @var bool
     */
    public $verbose;

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
            'outputDirectory',
            'outputFormat',
            'outputFilePattern',
        ]);
    }

    static protected $noticeLevelColors = [
        DefaultGenerator::NOTICE_SUCCESS => [Console::FG_GREEN],
        DefaultGenerator::NOTICE_IMPORTANT => [Console::BG_YELLOW, Console::FG_GREEN],
        DefaultGenerator::NOTICE_INFO => [Console::FG_YELLOW],
        DefaultGenerator::NOTICE_WARNING => [Console::FG_RED],
        DefaultGenerator::NOTICE_ERROR => [Console::BG_RED, Console::FG_BLACK],
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

            Console::output('Saving '.$this->outputFormat.' for '.$result_specification->id.' as '.$file_name);

            if (($written = file_put_contents($file_name, $data)) !== $data_size) {
                throw new \RuntimeException('Written '.$written.' byte(s) out of '.$data_size);
            }
        }

        return ExitCode::OK;
    }

    /**
     * @return DefaultGenerator
     */
    protected function constructGenerator(): DefaultGenerator
    {
        $generator = new DefaultGenerator();
        $generator->setOnNoticeCallback(function ($message, $level) {
            Console::output(Console::ansiFormat(
                ($this->currentPath !== null ? $this->currentPath.':' : null)
                .$message, static::$noticeLevelColors[$level]));
        });
        $generator->getClassDescriber()->setClassDescribingOptions(ActiveRecord::class, []);
        return $generator;
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
     * @param DefaultGenerator $generator
     * @return void
     */
    protected function setupGeneratorOutput(DefaultGenerator $generator): void
    {
        // Progress-bar
        $done = $total = 0;
        $this->currentPath = null;

        $generator->setOnSpecificationStartCallback(function (ResultSpecification $spec) use (&$total, &$done) {
            $done = 0;
            $total = count($spec->paths);
            Console::startProgress(0, $total, $spec->title);
        });
        $generator->setOnPathStartCallback(function (ResultPath $path, ResultSpecification $specification) {
            $this->currentPath = $specification->version.$path->id;
        });
        $generator->setOnPathEndCallback(function (ResultPath $path, ResultSpecification $specification) use (&$total, &$done) {
            $this->currentPath = null;
            $done++;
            Console::updateProgress($done, $total, $specification->version . ($this->verbose ? $path->id : null));
        });
        $generator->setOnSpecificationEndCallback(function (ResultSpecification $spec) use (&$total) {
            $total = 0;
            Console::endProgress();
        });
    }

    /**
     * @return DefaultScrapper
     */
    protected function buildScraper(): DefaultScrapper
    {
        $scraper = new $this->scraper();
        return $scraper;
    }
}
