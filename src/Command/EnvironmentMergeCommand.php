<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentMergeCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:merge')
            ->setAliases(array('merge'))
            ->setDescription('Merge an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment to merge')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Do not wait for the operation to complete');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment['id'];

        if (!$selectedEnvironment->operationAvailable('merge')) {
            $output->writeln("Operation not available: The environment <error>$environmentId</error> can't be merged.");
            return 1;
        }

        $parentId = $selectedEnvironment['parent'];

        if (!$this->getHelper('question')->confirm("Are you sure you want to merge <info>$environmentId</info> with its parent, <info>$parentId</info>?", $input, $output)) {
            return 0;
        }

        $activity = $selectedEnvironment->merge();
        if (!$input->getOption('no-wait')) {
            ActivityUtil::waitAndLog($activity, $output);
        }

        // Reload the stored environments.
        $this->getEnvironments(null, true);

        $output->writeln("The environment <info>$environmentId</info> has been merged with <info>$parentId</info>.");
        return 0;
    }
}
