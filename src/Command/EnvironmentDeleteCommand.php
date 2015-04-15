<?php

namespace Platformsh\Cli\Command;

use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeleteCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('environment:delete')
          ->setDescription('Delete an environment')
          ->addArgument('environment', InputArgument::IS_ARRAY, 'The environment(s) to delete')
          ->addOption('inactive', null, InputOption::VALUE_NONE, 'Delete all inactive environments');
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environments = $this->getEnvironments();

        if ($input->getOption('inactive')) {
            $toDelete = array_filter(
              $environments,
              function ($environment) {
                  /** @var Environment $environment */
                  return $environment->status == 'inactive';
              }
            );
            if (!$toDelete) {
                $output->writeln("No inactive environments found");

                return 0;
            }
        } elseif ($this->hasSelectedEnvironment()) {
            $toDelete = array($this->getSelectedEnvironment());
        } else {
            $environmentIds = $input->getArgument('environment');
            $toDelete = array_intersect_key($environments, array_flip($environmentIds));
            $notFound = array_diff($environmentIds, array_keys($environments));
            foreach ($notFound as $notFoundId) {
                $output->writeln("Environment not found: <error>$notFoundId</error>");
            }
        }

        $success = $this->deleteMultiple($toDelete, $input, $output);

        return $success ? 0 : 1;
    }

    /**
     * @param Environment[]   $environments
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function deleteMultiple(array $environments, InputInterface $input, OutputInterface $output)
    {
        $count = count($environments);
        $processed = 0;
        // Confirm which environments the user wishes to be deleted.
        $process = array();
        $questionHelper = $this->getHelper('question');
        foreach ($environments as $environment) {
            $environmentId = $environment['id'];
            if ($environmentId == 'master') {
                $output->writeln("The <error>master</error> environment cannot be deactivated or deleted.");
                continue;
            }
            if ($environment->isActive()) {
                $output->writeln(
                  "The environment <error>$environmentId</error> is active and therefore can't be deleted."
                );
                $output->writeln("Please deactivate the environment first.");
                continue;
            }
            if (!$environment->operationAvailable('delete')) {
                $output->writeln(
                  "Operation not available: The environment <error>$environmentId</error> can't be deleted."
                );
                continue;
            }
            // Check that the environment does not have children.
            // @todo remove this check when Platform's behavior is fixed
            foreach ($this->getEnvironments() as $potentialChild) {
                if ($potentialChild['parent'] == $environment['id']) {
                    $output->writeln(
                      "The environment <error>$environmentId</error> has children and therefore can't be deleted."
                    );
                    $output->writeln("Please delete the environment's children first.");
                    continue 2;
                }
            }
            $question = "Are you sure you want to delete the environment <info>$environmentId</info>?";
            if (!$questionHelper->confirm($question, $input, $output)) {
                continue;
            }
            $process[$environmentId] = $environment;
        }
        /** @var Environment $environment */
        foreach ($process as $environmentId => $environment) {
            try {
                $environment->delete();
                $processed++;
                $output->writeln("Deleted environment <info>$environmentId</info>");
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }
        if ($processed) {
            $this->getEnvironments(null, true);
        }

        return $processed >= $count;
    }

}
