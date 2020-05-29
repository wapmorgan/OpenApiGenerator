<?php
namespace wapmorgan\OpenApiGenerator\Console;

use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\PathItem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use wapmorgan\OpenApiGenerator\Generator\DefaultGenerator;
use wapmorgan\OpenApiGenerator\Generator\Result\GeneratorResultSpecification;
use wapmorgan\OpenApiGenerator\Scraper\DefaultScraper;

class InspectCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'inspect';

    public function configure()
    {
        $this->setDescription('Scrapes configuration and generates openapi specification')
            ->setHelp('This command allows you to scrape all services and see result of generation configuration.')
            ->addArgument('scraper', InputArgument::REQUIRED, 'The scraper class or file')
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
        $scraper_type = $input->getArgument('scraper');
        /** @var DefaultScraper $scraper */
        $scraper = new $scraper_type();
        $generator = new DefaultGenerator();
        $result = $generator->generate($scraper);

        /** @var GeneratorResultSpecification $specification */
        $specification = $result->specifications[0];

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
                    $path->summary !== \OpenApi\Annotations\UNDEFINED
                        ? $path->summary
                        : null,
                    implode(', ', array_map(function (Parameter $param) {
                        return $param->name;
                    }, $path_method->parameters)),
                ]);
            }
        }
        $table->render();

        return 0;
    }
}