<?php
namespace wapmorgan\OpenApiGenerator\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use wapmorgan\OpenApiGenerator\Scraper\DefaultScraper;

class ScrapeCommand extends Command
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

        $scrape_result = $scraper->scrape();

        $table = new Table($output);
        $table->setHeaders(['method', 'service', 'callable', 'tags']);

        foreach ($scrape_result->specifications[0]->endpoints as $endpoint) {
            $table->addRow([
                $endpoint->httpMethod,
                $endpoint->id,
                json_encode($endpoint->callback),
                implode(', ', $endpoint->tags)
            ]);
        }
        $table->render();

        return 0;
    }
}