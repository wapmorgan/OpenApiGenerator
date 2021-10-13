<?php
namespace wapmorgan\OpenApiGenerator\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use wapmorgan\OpenApiGenerator\Scraper\DefaultScraper;

class ScrapeCommand extends BasicCommand
{
    /**
     * @var string
     */
    protected static $defaultName = 'scrape';

    public function configure()
    {
        $this->setDescription('Scrapes configuration and lists all found services')
            ->setHelp('This command allows you to inspect all ready-to-scrape methods in current project.')
            ->addArgument('scraper', InputArgument::REQUIRED, 'The scraper class or file')
            ->addArgument('specification', InputArgument::OPTIONAL, 'Pattern for specifications', '.+')
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
        $this->output = $output;

        $scraper_type = $input->getArgument('scraper');

        $scraper = $this->createScraper($scraper_type, $output);
        $scraper->specificationPattern = $input->getArgument('specification');
        $scrape_result = $scraper->scrape();

        switch (count($scrape_result->specifications)) {
            case 0:
                $output->writeln('No available specifications');
                break;
            case 1:
                $this->printSpecification($output, null, $scrape_result->specifications[0]->endpoints);
                break;
            default:
                foreach ($scrape_result->specifications as $specification) {
                    $this->printSpecification($output, $specification->title.' '.$specification->version, $specification->endpoints);
                }
                break;
        }

        return 0;
    }

    protected function printSpecification(OutputInterface $output, ?string $title, array $endpoints)
    {
        if (!empty($title)) {
            $output->writeln($title);
        }
        $table = new Table($output);
        $table->setHeaders(['method', 'service', 'callable', 'tags']);
        foreach ($endpoints as $endpoint) {
            $table->addRow([
                $endpoint->httpMethod,
                $endpoint->id,
                json_encode($endpoint->callback, JSON_THROW_ON_ERROR),
                implode(', ', $endpoint->tags)
            ]);
        }
        $table->render();
    }
}