<?php
namespace wapmorgan\OpenApiGenerator\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use wapmorgan\OpenApiGenerator\ErrorableObject;
use wapmorgan\OpenApiGenerator\Generator\DefaultGenerator;
use wapmorgan\OpenApiGenerator\Scraper\DefaultScrapper;

class GenerateCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'generate';

    public function configure()
    {
        $this->setDescription('Generates openapi configurations')
            ->setHelp('This command allows you to generate openapi-files for current application via user-defined scraper.')
            ->addArgument('scraper', InputArgument::REQUIRED, 'The scraper class or file')
            ->addArgument('output', InputArgument::OPTIONAL, 'Folder for output files', getcwd())
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of output: json or yaml', 'yaml')
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
        /** @var DefaultScrapper $scraper */
        $scraper = new $scraper_type();

        $output_dir = rtrim($input->getArgument('output'), '/');
        if (!is_dir($output_dir)) {
            if (is_file($output_dir)) throw new \InvalidArgumentException($output_dir.' is not a folder');
            else if (!mkdir($output_dir, 0777, true)) throw new \InvalidArgumentException($output_dir.' could not be created');
        } else if (!is_writable($output_dir) && !chmod($output_dir, 0777)) throw new \InvalidArgumentException($output_dir.' could not be set writable');

        $output_format = $input->getOption('format');

        $generator = new DefaultGenerator();
//        $generator->setOnErrorCallback(function () use ($output) {
//            $this->onError($output, string $message, int $level);
//        });

        $generator->setOnNoticeCallback(function (string $message, int $level) use ($output) {
            $this->onNotice($output, $message, $level);
        });

        $result = $generator->generate($scraper);
        foreach ($result->specifications as $specification) {
            $specification_file = $output_dir.'/'.$specification->id.'.'.$output_format;

            $output->write('Writing '.$specification->id.' to '.$specification_file.' ... ');
            $specification->specification->saveAs($specification_file);
            $output->writeln('ok');
        }

        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @param int $level
     */
    public function onNotice(OutputInterface $output, string $message, int $level)
    {
        static $tags = [
            ErrorableObject::NOTICE_SUCCESS => 'info',
            ErrorableObject::NOTICE_IMPORTANT => 'info',
            ErrorableObject::NOTICE_INFO => 'comment',
            ErrorableObject::NOTICE_WARNING => 'error',
            ErrorableObject::NOTICE_ERROR => 'error',
        ];

        $output->writeln('<'.$tags[$level].'>'.$message.'</>');
    }
}
