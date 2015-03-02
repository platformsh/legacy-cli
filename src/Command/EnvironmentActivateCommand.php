<?php

namespace Platformsh\Cli\Command;

use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentActivateCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:activate')
            ->setDescription('Activate an environment')
            ->addArgument('environment', InputArgument::IS_ARRAY, 'The environment(s) to activate');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        if ($this->hasSelectedEnvironment()) {
            $toActivate = array($this->getSelectedEnvironment());
        }
        else {
            $environments = $this->getEnvironments();
            $environmentIds = $input->getArgument('environment');
            $toActivate = array_intersect_key($environments, array_flip($environmentIds));
            $notFound = array_diff($environmentIds, array_keys($environments));
            foreach ($notFound as $notFoundId) {
                $output->writeln("Environment not found: <error>$notFoundId</error>");
            }
        }

        $success = $this->activateMultiple($toActivate, $input, $output);

        return $success ? 0 : 1;
    }

    /**
     * @param Environment[]   $environments
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function activateMultiple(array $environments, InputInterface $input, OutputInterface $output)
    {
        $count = count($environments);
        $processed = 0;
        // Confirm which environments the user wishes to be deactivated.
        $process = array();
        $questionHelper = $this->getHelper('question');
        foreach ($environments as $environment) {
            $environmentId = $environment['id'];
            if ($environment->isActive()) {
                $output->writeln("The environment <info>$environmentId</info> is already active.");
                $count--;
                continue;
            }
            if (!$environment->operationAvailable('activate')) {
                $output->writeln("Operation not available: The environment <error>$environmentId</error> can't be activated.");
                continue;
            }
            $question = "Are you sure you want to activate the environment <info>$environmentId</info>?";
            if (!$questionHelper->confirm($question, $input, $output)) {
                continue;
            }
            $process[$environmentId] = $environment;
        }
        /** @var Environment $environment */
        foreach ($process as $environmentId => $environment) {
            try {
                $environment->activate();
                $processed++;
                $output->writeln("Activated environment <info>$environmentId</info>");
            }
            catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }
        if ($processed) {
            $this->getEnvironments(null, true);
        }
        return $processed >= $count;
    }

}
