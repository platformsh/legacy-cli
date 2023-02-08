<?php
/**
 * @file
 * Override Symfony Console's HelpCommand to customize the appearance of help.
 */

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\CustomMarkdownDescriptor;
use Platformsh\Cli\Console\CustomTextDescriptor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HelpCommand extends CommandBase
{

    protected $command;

    /**
     * @inheritdoc
     */
    public function setCommand(Command $command)
    {
        $this->command = $command;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->ignoreValidationErrors();

        $this
            ->setName('help')
            ->setDefinition([
                new InputArgument('command_name', InputArgument::OPTIONAL, 'The command name', 'help'),
                new InputOption('format', null, InputOption::VALUE_REQUIRED, 'The output format (txt, xml, json, or md)', 'txt'),
                new InputOption('raw', null, InputOption::VALUE_NONE, 'To output raw command help'),
            ])
            ->setDescription('Displays help for a command')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command displays help for a given command:

  <info>%command.full_name% list</info>

You can also output the help in other formats by using the <comment>--format</comment> option:

  <info>%command.full_name% --format=xml list</info>

To display the list of available commands, please use the <info>list</info> command.
EOF
            )
        ;
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (null === $this->command) {
            $this->command = $this->getApplication()
                                  ->find($input->getArgument('command_name'));
        }

        $config = new Config();

        $helper = new DescriptorHelper();
        $helper->register('txt', new CustomTextDescriptor($config->get('application.executable')));
        $helper->register('md', new CustomMarkdownDescriptor($config->get('application.executable')));
        $helper->describe(
            $output,
            $this->command,
            [
                'format' => $input->getOption('format'),
                'raw_text' => $input->getOption('raw'),
            ]
        );
    }
}
