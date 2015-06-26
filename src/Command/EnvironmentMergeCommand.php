<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\ActivityUtil;
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
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('Merge the environment "sprint-2" into its parent', 'sprint-2');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment['id'];

        if (!$selectedEnvironment->operationAvailable('merge')) {
            $this->stdErr->writeln("Operation not available: The environment <error>$environmentId</error> can't be merged.");

            return 1;
        }

        $parentId = $selectedEnvironment['parent'];

        if (!$this->getHelper('question')
                  ->confirm(
                    "Are you sure you want to merge <info>$environmentId</info> with its parent, <info>$parentId</info>?",
                    $input,
                    $this->stdErr
                  )
        ) {
            return 0;
        }

        $this->stdErr->writeln("Merging <info>$environmentId</info> with <info>$parentId</info>");

        $activity = $selectedEnvironment->merge();
        if (!$input->getOption('no-wait')) {
            $success = ActivityUtil::waitAndLog(
              $activity,
              $this->stdErr,
              'Merge complete',
              'Merge failed'
            );
            if (!$success) {
                return 1;
            }
        }

        // Reload the stored environments.
        $this->getEnvironments(null, true);

        return 0;
    }
}
