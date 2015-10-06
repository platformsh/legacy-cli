<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\PlatformCommand;
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
          ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter activities by type')
          ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', 5)
          ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output tab-separated results')
          ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
          ->setDescription('Get the most recent activities for an environment');
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('List recent activities on the current environment')
          ->addExample('List recent pushes', '--type environment.push');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        $startsAt = null;
        if ($input->getOption('start') && !($startsAt = strtotime($input->getOption('start')))) {
            $this->stdErr->writeln('Invalid date: <error>' . $input->getOption('start') . '</error>');
            return 1;
        }

        $limit = (int) $input->getOption('limit');
        $activities = $environment->getActivities($limit, $input->getOption('type'), $startsAt);
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

        if ($output instanceof StreamOutput && $input->getOption('pipe')) {
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
