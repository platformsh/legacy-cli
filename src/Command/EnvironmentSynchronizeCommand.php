<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSynchronizeCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:synchronize')
            ->setDescription('Synchronize an environment.')
            ->addArgument(
                'synchronize',
                InputArgument::IS_ARRAY,
                'What to synchronize: code, data or both',
                null
            )
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project id'
            )
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_OPTIONAL,
                'The environment id'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }
        if (!$this->operationAllowed('synchronize')) {
            $output->writeln("<error>Operation not permitted: The current environment can't be synchronized.</error>");
            return;
        }

        if ($synchronize = $input->getArgument('synchronize')) {
          $syncCode = in_array('code', $synchronize) || in_array('both', $synchronize);
          $syncData = in_array('data', $synchronize) || in_array('both', $synchronize);
        }
        else {
          $dialog = $this->getHelperSet()->get('dialog');
          $syncCodeText = "Synchronize code? [Y/N] ";
          $syncDataText = "Synchronize data? [Y/N] ";
          $syncCode = $dialog->askConfirmation($output, $syncCodeText, false);
          $syncData = $dialog->askConfirmation($output, $syncDataText, false);
        }
        if (!$syncCode && !$syncData) {
            $output->writeln("<error>You must synchronize at least code or data.</error>");
            return;
        }

        $params = array(
            'synchronize_code' => $syncCode,
            'synchronize_data' => $syncData,
        );
        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->synchronizeEnvironment($params);

        $environmentId = $this->environment['id'];
        $message = '<info>';
        $message .= "\nThe environment $environmentId has been synchronized. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
