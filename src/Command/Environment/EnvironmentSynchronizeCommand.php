<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\PlatformCommand;
use Platformsh\Cli\Util\ActivityUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSynchronizeCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('environment:synchronize')
          ->setAliases(array('sync'))
          ->setDescription('Synchronize an environment')
          ->addArgument(
            'synchronize',
            InputArgument::IS_ARRAY,
            'What to synchronize: code, data or both',
            null
          );
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addNoWaitOption();
        $this->addExample('Synchronize data from the parent environment', 'data');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment['id'];

        if (!$selectedEnvironment->operationAvailable('synchronize')) {
            $this->stdErr->writeln(
              "Operation not available: The environment <error>$environmentId</error> can't be synchronized."
            );

            return 1;
        }

        $parentId = $selectedEnvironment['parent'];

        $questionHelper = $this->getHelper('question');

        if ($synchronize = $input->getArgument('synchronize')) {
            // The input was invalid.
            if (array_diff($input->getArgument('synchronize'), array('code', 'data', 'both'))) {
                $this->stdErr->writeln("Specify 'code', 'data', or 'both'");
                return 1;
            }
            $syncCode = in_array('code', $synchronize) || in_array('both', $synchronize);
            $syncData = in_array('data', $synchronize) || in_array('both', $synchronize);
            if (!$questionHelper->confirm(
              "Are you sure you want to synchronize <info>$parentId</info> to <info>$environmentId</info>?",
              $input,
              $this->stdErr,
              false
            )
            ) {
                return 0;
            }
        } else {
            $syncCode = $questionHelper->confirm(
              "Synchronize code from <info>$parentId</info> to <info>$environmentId</info>?",
              $input,
              $this->stdErr,
              false
            );
            $syncData = $questionHelper->confirm(
              "Synchronize data from <info>$parentId</info> to <info>$environmentId</info>?",
              $input,
              $this->stdErr,
              false
            );
        }
        if (!$syncCode && !$syncData) {
            $this->stdErr->writeln("<error>You must synchronize at least code or data.</error>");

            return 1;
        }

        $this->stdErr->writeln("Synchronizing environment <info>$environmentId</info>");

        $activity = $selectedEnvironment->synchronize($syncData, $syncCode);
        if (!$input->getOption('no-wait')) {
            $success = ActivityUtil::waitAndLog(
              $activity,
              $this->stdErr,
              "Synchronization complete",
              "Synchronization failed"
            );
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
