<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
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

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $environment = new Environment($this->environment, $client);

        $results = $environment->getActivities(/*$input->getOption('limit')*/0, $input->getOption('type'));
        if (!$results) {
            $output->writeln('No activities found');
            return 1;
        }

        $headers = array("ID", "Created", "Description", "% Complete", "Result");
        $rows = array();
        foreach ($results as $result) {
            $description = $result->getDescription();
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

        $output->writeln("Recent activities for the environment <info>" . $this->environment['id'] . "</info>");
        $table = new Table($output);
        $table->setHeaders($headers);
        $table->addRows($rows);
        $table->render();

        return 0;
    }

}
