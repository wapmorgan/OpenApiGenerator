<?php
namespace wapmorgan\OpenApiGenerator\Console;

use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\PathItem;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use wapmorgan\OpenApiGenerator\Generator\DefaultGenerator;
use wapmorgan\OpenApiGenerator\Generator\Result\GeneratorResult;
use wapmorgan\OpenApiGenerator\Generator\Result\GeneratorResultSpecification;
use wapmorgan\OpenApiGenerator\Scraper\DefaultScraper;

class GenerateCommand extends BasicCommand
{
    /**
     * @var string
     */
    protected static $defaultName = 'generate';

    public function configure()
    {
        $scrapers = array_keys(DefaultScraper::getAllDefaultScrapers());

        $this->setDescription('Generates openapi configurations.'.PHP_EOL
            .'  Default scrapers: '.implode(', ', $scrapers).'.')
            ->setHelp('This command allows you to generate openapi-files for current application via user-defined scraper.')
            ->addOption('scraper', null, InputOption::VALUE_REQUIRED, 'The scraper class or file')
            ->addOption('generator', 'g', InputOption::VALUE_REQUIRED, 'The generator class or file', DefaultGenerator::class)
            ->addOption('specification', null, InputOption::VALUE_REQUIRED, 'Pattern for specifications', '.+')
            ->addArgument('output', InputArgument::OPTIONAL, 'Folder for output files', getcwd())
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of output: json or yaml', 'yaml')
            ->addOption('inspect', null, InputOption::VALUE_NONE, 'Probe run')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void
     * @throws \ReflectionException
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setUpStyles($output);
        $scraper = $input->getOption('scraper');
        if (empty($scraper)) {
            throw new \InvalidArgumentException('Set a scraper');
        }

        $scraper = $this->createScraper($scraper, $output);
        $scraper->specificationPattern = $input->getOption('specification');

        $generator = $this->createGenerator($input->getOption('generator'), $output);
        $result = $generator->generate($scraper);

        $is_inspect_mode = (boolean)$input->getOption('inspect');

        if ($is_inspect_mode)
            $this->inspect($input, $output, $result);
        else
            $this->generate($input, $output, $result);

        return 0;
    }

    public function generate(InputInterface $input, OutputInterface $output, GeneratorResult $result)
    {
        $output_dir = rtrim($input->getArgument('output'), '/');
        if (!is_dir($output_dir)) {
            if (is_file($output_dir)) throw new \InvalidArgumentException($output_dir.' is not a folder');
            else if (!mkdir($output_dir, 0777, true)) throw new \InvalidArgumentException($output_dir.' could not be created');
        } else if (!is_writable($output_dir) && !chmod($output_dir, 0777)) throw new \InvalidArgumentException($output_dir.' could not be set writable');

        $output_format = $input->getOption('format');

        foreach ($result->specifications as $specification) {
            $specification_file = $output_dir.'/'.$specification->id.'.'.$output_format;

            $output->write('Writing '.$specification->id.' to '.$specification_file.' ... ');
            $specification->specification->saveAs($specification_file);
            $output->writeln('ok');
        }
    }

    public function inspect(InputInterface $input, OutputInterface $output, GeneratorResult $result)
    {

        switch (count($result->specifications)) {
            case 0:
                $output->writeln('No available specifications');
                break;
            case 1:
                $this->printSpecification($output, null, $result->specifications[0]);
                break;
            default:
                $output->writeln('Total '.count($result->specifications).' specification(s)');
                foreach ($result->specifications as $specification) {
                    $this->printSpecification($output, $specification->title.' '.$specification->id, $specification);
                }
                break;
        }
        return 0;
    }

    protected function printSpecification(OutputInterface $output, ?string $title, GeneratorResultSpecification $specification)
    {
        if (!empty($title)) {
            $output->writeln($title);
        }

        $table = new Table($output);
        $table->setHeaders(['path', 'method', 'descripton', 'parameters']);

        /** @var PathItem $path */
        foreach ($specification->specification->paths as $pathId => $path) {
            foreach (['get', 'post'] as $method) {
                if (!isset($path->{$method}) || $path->{$method} === \OpenApi\Annotations\UNDEFINED)
                    continue;

                /** @var Operation $path_method */
                $path_method = $path->{$method};

                $table->addRow([
                                   $path->path,
                                   $method,
                                   $path_method->summary !== \OpenApi\Annotations\UNDEFINED
                                       ? mb_substr($path_method->summary, 0, 10)
                                       : null,
                                   implode(', ', array_map(function (Parameter $param) {
                                       return $param->name;
                                   }, $path_method->parameters)),
                               ]);
            }
        }
        $table->render();
    }
}
