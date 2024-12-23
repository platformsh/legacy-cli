<?php

declare(strict_types=1);

/**
 * @file
 * Override Symfony Console's ListCommand to customize the list's appearance.
 */

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\CustomJsonDescriptor;
use Platformsh\Cli\Console\CustomMarkdownDescriptor;
use Platformsh\Cli\Console\CustomTextDescriptor;
use Platformsh\Cli\Service\Config;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends CommandBase
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
        parent::setConfig($this->config);
    }

    protected function configure(): void
    {
        $this->setName('list')
            ->setDescription('List commands')
            ->setDefinition([
                new InputArgument('namespace', InputArgument::OPTIONAL, 'The namespace name'),
                new InputOption('raw', null, InputOption::VALUE_NONE, 'To output raw command list'),
                new InputOption('format', null, InputOption::VALUE_REQUIRED, 'The output format (txt, xml, json, or md)', 'txt'),
            ])
            ->setHelp(
                <<<'EOF'
                    The <info>%command.name%</info> command lists all commands:

                      <info>%command.full_name%</info>

                    You can also display the commands for a specific namespace:

                      <info>%command.full_name% project</info>

                    You can also output the information in other formats by using the <comment>--format</comment> option:

                      <info>%command.full_name% --format=xml</info>

                    It's also possible to get raw list of commands (useful for embedding command runner):

                      <info>%command.full_name% --raw</info>
                    EOF,
            )
        ;
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Show all commands, including hidden ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = new DescriptorHelper();
        $helper->register('txt', new CustomTextDescriptor($this->config->getStr('application.executable')));
        $helper->register('md', new CustomMarkdownDescriptor($this->config->getStr('application.executable')));
        $helper->register('json', new CustomJsonDescriptor());
        $helper->describe(
            $output,
            $this->getApplication(),
            [
                'format' => $input->getOption('format'),
                'raw_text' => $input->getOption('raw'),
                'namespace' => $input->getArgument('namespace'),
                'all' => $input->getOption('all'),
            ],
        );
        return 0;
    }
}
