<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use CommerceGuys\Platform\Cli\Model\HalResource;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\StreamOutput;

class ActivityListCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('activity:list')
            ->setAliases(array('activities'))
            ->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Filter activities by type')
            //->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit the number of results displayed', 3)
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output tab-separated results')
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project ID')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment ID')
            ->setDescription('Get the most recent activities for an environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environment = new Environment($this->environment);
        $environment->setClient($this->getPlatformClient($this->environment['endpoint']));

        $results = $environment->getActivities(/*$input->getOption('limit')*/0, $input->getOption('type'));
        if (!$results) {
            $output->writeln('No activities found');
            return 1;
        }

        $headers = array("ID", "Created", "Description", "% Complete", "Result");
        $rows = array();
        foreach ($results as $result) {
            $description = $this->getActivityDescription($result);
            $description = wordwrap($description, 40);
            $rows[] = array(
              $result->id(),
              $result->getDate(),
              $description,
              $result->getProperty('completion_percent'),
              $result->getProperty('result', false),
            );
        }

        if ($output instanceof StreamOutput && ($input->getOption('pipe') || !$this->isTerminal($output))) {
            $stream = $output->getStream();
            array_unshift($rows, $headers);
            foreach ($rows as $row) {
                fputcsv($stream, $row, "\t");
            }
            return 0;
        }

        $output->writeln("Recent activities for the environment <info>" . $environment->id() . "</info>");
        $table = new Table($output);
        $table->setHeaders($headers);
        $table->addRows($rows);
        $table->render();

        return 0;
    }

    protected function getActivityDescription(HalResource $activity)
    {
        $data = $activity->getProperties();
        switch ($data['type']) {
            case 'environment.activate':
                return sprintf(
                  "%s activated environment %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.backup':
                return sprintf(
                  "%s created backup of %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.branch':
                return sprintf(
                  "%s branched %s from %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $data['payload']['parent']['title']
                );

            case 'environment.delete':
                return sprintf(
                  "%s deleted environment %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.deactivate':
                return sprintf(
                  "%s deactivated environment %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.initialize':
                return sprintf(
                  "%s initialized environment %s with profile %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $data['payload']['profile']
                );

            case 'environment.merge':
                return sprintf(
                  "%s merged %s into %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $data['payload']['environment']['title']
                );

            case 'environment.push':
                return sprintf(
                  "%s pushed to %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.synchronize':
                $syncedCode = !empty($data['payload']['synchronize_code']);
                if ($syncedCode && !empty($data['payload']['synchronize_data'])) {
                    $syncType = 'code and data';
                } elseif ($syncedCode) {
                    $syncType = 'code';
                } else {
                    $syncType = 'data';
                }
                return sprintf(
                  "%s synced %s's %s with %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $syncType,
                  $data['payload']['environment']['title']
                );
        }
        return $data['type'];
    }

}
