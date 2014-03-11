<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class EnvironmentMergeCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:merge')
            ->setDescription('Merge an environment.')
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

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->mergeEnvironment();
        // Refresh the stored environments, to trigger a drush alias rebuild.
        $this->getEnvironments($this->project, true);

        $environmentId = $this->environment['id'];
        $message = '<info>';
        $message = "\nThe environment $environmentId has been merged. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
