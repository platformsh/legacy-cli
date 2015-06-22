<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
          ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit the number of results displayed', 5)
          ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output tab-separated results')
          ->setDescription('Get the most recent activities for an environment');
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        $limit = (int) $input->getOption('limit');
        $activities = $environment->getActivities($limit, $input->getOption('type'));
        if (!$activities) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        $headers = array("ID", "Created", "Description", "% Complete", "Result");
        $rows = array();
        foreach ($activities as $activity) {
            $description = $activity->getDescription();
            $description = wordwrap($description, 40);
            $rows[] = array(
              $activity['id'],
              date('Y-m-d H:i:s', strtotime($activity['created_at'])),
              $description,
              $activity->getCompletionPercent(),
              $activity->state,
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

        $this->stdErr->writeln("Recent activities for the environment <info>" . $environment['id'] . "</info>");
        $table = new Table($output);
        $table->setHeaders($headers);
        $table->addRows($rows);
        $table->render();

        return 0;
    }

}
