<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use CommerceGuys\Platform\Cli\Model\HalResource;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class ActivityLogCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('activity:log')
            ->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.')
            ->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Filter activities by type')
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project ID')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment ID')
            ->setDescription('Display the log for an environment activity');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);

        $id = $input->getArgument('id');
        if ($id) {
            $activity = HalResource::get($id, $this->environment['endpoint'] . '/activities', $client);
            if (!$activity) {
                $output->writeln("Activity not found: <error>$id</error>");
                return 1;
            }
        }
        else {
            $environment = new Environment($this->environment, $client);
            $activity = reset($environment->getActivities(1, $input->getOption('type')));
            if (!$activity) {
                $output->writeln('No activities found');
                return 1;
            }
        }

        $output->write($activity->getProperty('log'));
        return 0;
    }

}
