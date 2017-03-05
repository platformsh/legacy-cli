<?php
namespace Platformsh\Cli\Command\Config;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Descriptor\ApplicationDescription;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigGenerateCommand extends CommandBase
{
    protected $local = true;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('config:generate')
            ->setDescription('Generate configuration for a project and/or application');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $description = new ApplicationDescription($this->getApplication(), 'config:generate');
        $commands = $description->getCommands();
        $commandOptions = [];
        foreach ($commands as $command) {
            $commandOptions[$command->getName()] = sprintf(
                '<comment>%s</comment>: %s', $command->getName(), $command->getDescription()
            );
        }

        if (!$input->isInteractive()) {
            $this->stdErr->writeln(sprintf('The <error>%s</error> command cannot be run non-interactively.', $this->getName()));
            $this->stdErr->writeln('Use one of the following commands directly:');
            $this->stdErr->writeln('  ' . implode("\n  ", $commandOptions));

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $commandName = $questionHelper->choose($commandOptions, 'Enter a number to choose a command:');

        return $this->runOtherCommand($commandName, [], $output);
    }
}
