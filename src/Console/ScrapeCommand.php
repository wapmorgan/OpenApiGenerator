<?php
namespace wapmorgan\OpenApiGenerator\Console;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use wapmorgan\OpenApiGenerator\Scraper\Endpoint;
use wapmorgan\OpenApiGenerator\ScraperSkeleton;

class ScrapeCommand extends BasicCommand
{
    /**
     * @var string
     */
    protected static $defaultName = 'scrape';

    public function configure()
    {
        $scrapers = array_keys(ScraperSkeleton::getAllDefaultScrapers());

        $this->setDescription('Scrapes configuration and lists all found services.'.PHP_EOL
              .'  Default scrapers: '.implode(', ', $scrapers).'.')
            ->setHelp('This command allows you to inspect all ready-to-scrape methods in current project.')
            ->addOption('scraper', null, InputOption::VALUE_REQUIRED, 'The scraper class or file')
            ->addOption('specification', null, InputOption::VALUE_REQUIRED, 'Pattern for specifications', '.+')
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

        $scraper = $input->getOption('scraper');
        if (empty($scraper)) {
            throw new \InvalidArgumentException('Set a scraper');
        }

        $scraper = $this->createScraper($scraper, $output);
        $scraper->specificationPattern = $input->getOption('specification');
        $scrape_result = $scraper->scrape();

        switch (count($scrape_result)) {
            case 0:
                $output->writeln('No available specifications');
                break;
            case 1:
                $this->printSpecification($output, null, $scrape_result[0]->endpoints);
                break;
            default:
                foreach ($scrape_result as $specification) {
                    $this->printSpecification($output, $specification->title.' '.$specification->version, $specification->endpoints);
                }
                break;
        }

        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param string|null $title
     * @param Endpoint[] $endpoints
     * @return void
     * @throws \JsonException
     */
    protected function printSpecification(OutputInterface $output, ?string $title, array $endpoints)
    {
        if (!empty($title)) {
            $output->writeln($title);
        }
        $table = new Table($output);
        $table->setHeaders(['service', 'security', 'callable', 'result']);
        foreach ($endpoints as $endpoint) {
            $table->addRow([
                $endpoint->httpMethod.' '.$endpoint->id,
                implode(', ', $endpoint->securitySchemes),
                implode('::', $endpoint->callback),
                is_object($endpoint->result) ? 'o:'.get_class($endpoint->result) :
                    var_export($endpoint->result, true),
            ]);
        }
        $table->render();
    }
}
