<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSynchronizeCommand extends EnvironmentCommand
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
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environmentId = $this->environment['id'];

        if (!$this->operationAvailable('synchronize')) {
            $output->writeln("Operation not available: The environment <error>$environmentId</error> can't be synchronized.");
            return 1;
        }

        $parentId = $this->environment['parent'];

        $questionHelper = $this->getHelper('question');

        if ($synchronize = $input->getArgument('synchronize')) {
            // The input was invalid.
            if (array_diff($input->getArgument('synchronize'), array('code', 'data', 'both'))) {
                $output->writeln("Specify 'code', 'data', or 'both'");
                return 1;
            }
            $syncCode = in_array('code', $synchronize) || in_array('both', $synchronize);
            $syncData = in_array('data', $synchronize) || in_array('both', $synchronize);
            if (!$questionHelper->confirm("Are you sure you want to synchronize <info>$parentId</info> to <info>$environmentId</info>?", $input, $output, false)) {
                return 0;
            }
        }
        else {
            $syncCode = $questionHelper->confirm("Synchronize code from <info>$parentId</info> to <info>$environmentId</info>?", $input, $output, false);
            $syncData = $questionHelper->confirm("Synchronize data from <info>$parentId</info> to <info>$environmentId</info>?", $input, $output, false);
        }
        if (!$syncCode && !$syncData) {
            $output->writeln("<error>You must synchronize at least code or data.</error>");
            return 1;
        }

        $output->writeln("Synchronizing environment <info>$environmentId</info>");

        $params = array(
            'synchronize_code' => $syncCode,
            'synchronize_data' => $syncData,
        );
        $client = $this->getPlatformClient($this->environment['endpoint']);
        $response = $client->synchronizeEnvironment($params);
        if (!$input->getOption('no-wait')) {
            $success = Activity::waitAndLog(
              $response,
              $client,
              $output,
              "Synchronization complete",
              "Synchronization failed"
            );
            if ($success === false) {
                return 1;
            }
        }

        return 0;
    }
}
