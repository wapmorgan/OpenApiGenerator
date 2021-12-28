<?php
namespace wapmorgan\OpenApiGenerator\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\Generator\DefaultGenerator;
use wapmorgan\OpenApiGenerator\Scraper\Endpoint;
use wapmorgan\OpenApiGenerator\Scraper\Specification;
use wapmorgan\OpenApiGenerator\ScraperSkeleton;

abstract class BasicCommand extends Command
{
    public $progressPrefix;
    /**
     * @var OutputInterface
     */
    protected OutputInterface $output;

    /**
     * @var ProgressBar|null
     */
    protected ?ProgressBar $progressBar;

    protected function setUpStyles(OutputInterface $output)
    {
        $output->getFormatter()->setStyle('notice_success', new OutputFormatterStyle('green', null));
        $output->getFormatter()->setStyle('notice_important', new OutputFormatterStyle('white', 'blue'));
        $output->getFormatter()->setStyle('notice_info', new OutputFormatterStyle('default', 'black'));
        $output->getFormatter()->setStyle('notice_warning', new OutputFormatterStyle('red', null));
        $output->getFormatter()->setStyle('notice_error', new OutputFormatterStyle('black', 'red'));
        $output->getFormatter()->setStyle('trace', new OutputFormatterStyle('default', 'black'));
    }

    /**
     * @param string $scraperType
     * @param OutputInterface $output
     * @return ScraperSkeleton
     */
    protected function createScraper(string $scraperType, OutputInterface $output): ScraperSkeleton
    {
        $scrapers = ScraperSkeleton::getAllDefaultScrapers();
        if (isset($scrapers[$scraperType]))
            $scraperType = $scrapers[$scraperType];

        if (class_exists($scraperType)) {
            return new $scraperType();
        }

        if (!empty($scraperType) && file_exists($file = realpath(getcwd().'/'.$scraperType))) {
            $classes_before = get_declared_classes();
            require_once $file;
            $new_classes = array_diff(get_declared_classes(), $classes_before);
            foreach ($new_classes as $new_class) {
                if (is_subclass_of($new_class, ScraperSkeleton::class)) {
                    return $this->setMessagesCallbacks(new $new_class, $output);
                }
            }
        }

        throw new \InvalidArgumentException('Invalid scraper: '.$scraperType);
    }

    /**
     * @param string $generatorType
     * @param OutputInterface $output
     * @return DefaultGenerator
     */
    public function createGenerator(string $generatorType, OutputInterface $output): DefaultGenerator
    {
        if (class_exists($generatorType)) {
            return new $generatorType();
        }

        $file = realpath(getcwd().'/'.$generatorType);
        if (file_exists($file)) {
            $classes_before = get_declared_classes();
            require_once $file;
            $new_classes = array_diff(get_declared_classes(), $classes_before);
            foreach ($new_classes as $new_class) {
                if (is_subclass_of($new_class, DefaultGenerator::class)) {
                    return $this->setGeneratorCallbacks($output, $this->setMessagesCallbacks(new $new_class, $output));
                }
            }
        }

        throw new \InvalidArgumentException('Invalid generator: '.$generatorType);
    }

    /**
     * @param ErrorableObject $emitter
     * @param OutputInterface $output
     * @return ErrorableObject
     */
    public function setMessagesCallbacks(ErrorableObject $emitter, OutputInterface $output): ErrorableObject
    {
        $emitter->setOnNoticeCallback(function(string $message, int $level) use ($output) {
            $this->onNoticeCallback($output, $message, $level);
        });
        $emitter->setOnTraceCallback(function(string $message) use ($output) {
            $this->onTraceCallback($output, $message);
        });
        return $emitter;
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @param int $level
     */
    public function onNoticeCallback(OutputInterface $output, string $message, int $level)
    {
        static $tags = [
            ErrorableObject::NOTICE_SUCCESS => ['notice_success', OutputInterface::VERBOSITY_NORMAL],
            ErrorableObject::NOTICE_IMPORTANT => ['notice_important', OutputInterface::VERBOSITY_VERBOSE],
            ErrorableObject::NOTICE_INFO => ['notice_info', OutputInterface::VERBOSITY_VERY_VERBOSE],
            ErrorableObject::NOTICE_WARNING => ['notice_warning', OutputInterface::VERBOSITY_NORMAL],
            ErrorableObject::NOTICE_ERROR => ['notice_error', OutputInterface::VERBOSITY_QUIET],
        ];

        if (isset($this->progressBar))
            $this->progressBar->clear();

        /** @var FormatterHelper $formatter */
        $formatter = $this->getHelper('formatter');
        $message = $formatter->formatSection(
            $this->progressPrefix ?? 'scrape',
            '<'.$tags[$level][0].'>'.$message.'</>');

        $output->writeln($message, $tags[$level][1]);

        if (isset($this->progressBar))
            $this->progressBar->display();
    }

    /**
     * @param OutputInterface $output
     * @param $message
     */
    public function onTraceCallback(OutputInterface $output, $message)
    {
        $output->writeln(
            '<trace>'
            .($this->progressPrefix !== null ? $this->progressPrefix.': ' : null).$message
            .'</trace>', OutputInterface::VERBOSITY_DEBUG
        );
    }

    public function setGeneratorCallbacks(OutputInterface $output, DefaultGenerator $generator)
    {
        ProgressBar::setFormatDefinition('generator', ' %current%/%max% [%bar%] %percent:3s%% (%message%) ');

        $done = $total = 0;
        $progressPrefix = &$this->progressPrefix;
        $progressBar = null;

        $generator->setOnSpecificationStartCallback(function (Specification $spec)
            use (&$done, &$total, &$progressPrefix, &$progressBar, $output) {
            $done = 0;
            $total = count($spec->endpoints);
            $this->progressBar = $progressBar = new ProgressBar($output, $total);
            $progressBar->setFormat('generator');
            $progressBar->start();
        });
        $generator->setOnSpecificationEndCallback(static function (Specification $spec)
            use (&$done, &$progressBar) {
            $progressBar->finish();
            $progressBar->clear();
            $done = 0;
        });
        $generator->setOnPathStartCallback(static function (Endpoint $path, Specification $specification)
            use (&$progressPrefix, &$progressBar) {
            $progressPrefix = $specification->version.$path->id;
            $progressBar->setMessage($progressPrefix);
        });
        $generator->setOnPathEndCallback(static function (Endpoint $path, Specification $specification)
            use (&$done, &$progressPrefix, &$progressBar) {
            $done++;
            $progressBar->advance();
            $progressPrefix = null;
        });

        return $generator;
    }
}
