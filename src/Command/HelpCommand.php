<?php
/**
 * @file
 * Override Symfony Console's HelpCommand to customize the appearance of help.
 */

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\CustomJsonDescriptor;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\CustomMarkdownDescriptor;
use Platformsh\Cli\Console\CustomTextDescriptor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\XmlDescriptor;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'help', description: 'Displays help for a command')]
class HelpCommand extends CommandBase
{

    protected $command;

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
            ->setDefinition([
                new InputArgument('command_name', InputArgument::OPTIONAL, 'The command name', 'help'),
                new InputOption('format', null, InputOption::VALUE_REQUIRED, 'The output format (txt, json, or md)', 'txt'),
                new InputOption('raw', null, InputOption::VALUE_NONE, 'To output raw command help'),
            ])
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command displays help for a given command:

  <info>%command.full_name% list</info>

You can also output the help in other formats by using the <comment>--format</comment> option:

  <info>%command.full_name% --format=json list</info>

To display the list of available commands, please use the <info>list</info> command.
EOF
            )
        ;
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null === $this->command) {
            $this->command = $this->getApplication()
                                  ->find($input->getArgument('command_name'));
        }

        $config = new Config();

        $format = $input->getOption('format');
        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $options = ['format' => $format, 'raw_text' => $input->getOption('raw'), 'all' => true];

        switch ($format) {
            case 'xml':
                $stdErr->writeln('<options=reverse>DEPRECATED</> The <comment>xml</comment> help format is deprecated and will be removed in a future version.');
                if (!extension_loaded('simplexml')) {
                    $stdErr->writeln('It depends on the <comment>simplexml</comment> PHP extension which is not installed.');
                    return 1;
                }
                $stdErr->writeln('');
                (new XmlDescriptor())->describe($output, $this->command, $options);
                return 0;
            case 'md':
                (new CustomMarkdownDescriptor())->describe($output, $this->command, $options);
                return 0;
            case 'json':
                (new CustomJsonDescriptor())->describe($output, $this->command, $options);
                return 0;
            case 'txt':
                (new CustomTextDescriptor($config->get('application.executable')))->describe($output, $this->command, $options);
                return 0;
        }

        $originalCommand = $this->getApplication()->find($input->getFirstArgument());
        if ($originalCommand->getName() !== 'help' && $originalCommand->getDefinition()->hasOption('format')) {
            // If the --format is unrecognised, it might be because the
            // command has its own --format option. Fall back to plain text
            // help.
            (new CustomTextDescriptor($config->get('application.executable')))->describe($output, $this->command, $options);
            return 0;
        }

        throw new InvalidArgumentException(sprintf('Unsupported format "%s".', $format));
    }
}
