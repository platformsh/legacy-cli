<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeleteCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:delete')
            ->setDescription('Delete an environment')
            ->addArgument('environment', InputArgument::IS_ARRAY, 'The environment(s) to delete');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environments = $this->environment ? array($this->environment) : $input->getArgument('environment');

        $success = $this->deleteMultiple($environments, $input, $output);

        return $success ? 0 : 1;
    }

    /**
     * @param array           $environments
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
            if (!is_array($environment)) {
                $environment = $this->getEnvironment($environment, $this->project);
                if (!$environment) {
                    continue;
                }
            }
            $environmentId = $environment['id'];
            if ($environmentId == 'master') {
                $output->writeln("The <error>master</error> environment cannot be deactivated or deleted.");
                continue;
            }
            if (!empty($environment['_links']['public-url'])) {
                $output->writeln("The environment <error>$environmentId</error> is active and therefore can't be deleted.");
                $output->writeln("Please deactivate the environment first.");
                continue;
            }
            if (!$this->operationAllowed('delete', $environment)) {
                $output->writeln("Operation not permitted: The environment <error>$environmentId</error> can't be deleted.");
                continue;
            }
            // Check that the environment does not have children.
            // @todo remove this check when Platform's behavior is fixed
            foreach ($this->getEnvironments($this->project) as $potentialChild) {
                if ($potentialChild['parent'] == $environment['id']) {
                    $output->writeln("The environment <error>$environmentId</error> has children and therefore can't be deleted.");
                    $output->writeln("Please delete the environment's children first.");
                    continue;
                }
            }
            $question = "Are you sure you want to delete the environment <info>$environmentId</info>?";
            if (!$questionHelper->confirm($question, $input, $output)) {
                continue;
            }
            $process[$environmentId] = $environment;
        }
        foreach ($process as $environmentId =>  $environment) {
            $client = $this->getPlatformClient($environment['endpoint']);
            try {
                $client->deleteEnvironment();
                $processed++;
                $output->writeln("Deleted environment <info>$environmentId</info>");
            }
            catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }
        return $processed >= $count;
    }

}
