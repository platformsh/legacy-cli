<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentActivateCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:activate')
            ->setDescription('Activate an environment')
            ->addArgument('environment', InputArgument::IS_ARRAY, 'The environment(s) to activate');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addNoWaitOption();
        $this->addExample('Activate the environments "develop" and "stage"', 'develop stage');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        if ($this->hasSelectedEnvironment()) {
            $toActivate = [$this->getSelectedEnvironment()];
        } else {
            $environments = $this->api()->getEnvironments($this->getSelectedProject());
            $environmentIds = $input->getArgument('environment');
            $toActivate = array_intersect_key($environments, array_flip($environmentIds));
            $notFound = array_diff($environmentIds, array_keys($environments));
            foreach ($notFound as $notFoundId) {
                $this->stdErr->writeln("Environment not found: <error>$notFoundId</error>");
            }
        }

        $success = $this->activateMultiple($toActivate, $input, $this->stdErr);

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
        // Confirm which environments the user wishes to be activated.
        $process = [];
        $questionHelper = $this->getHelper('question');
        foreach ($environments as $environment) {
            $environmentId = $environment->id;
            if ($environment->isActive()) {
                $output->writeln("The environment <info>$environmentId</info> is already active.");
                $count--;
                continue;
            }
            if (!$environment->operationAvailable('activate')) {
                $output->writeln(
                    "Operation not available: The environment <error>$environmentId</error> can't be activated."
                );
                continue;
            }
            $question = "Are you sure you want to activate the environment <info>$environmentId</info>?";
            if (!$questionHelper->confirm($question)) {
                continue;
            }
            $process[$environmentId] = $environment;
        }
        $activities = [];
        /** @var Environment $environment */
        foreach ($process as $environmentId => $environment) {
            try {
                $activities[] = $environment->activate();
                $processed++;
                $output->writeln("Activating environment <info>$environmentId</info>");
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }

        $success = true;
        if ($processed) {
            if (!$input->getOption('no-wait')) {
                ActivityUtil::waitMultiple($activities, $this->stdErr, $this->getSelectedProject());
            }
            $this->api()->clearEnvironmentsCache($this->getSelectedProject()->id);
        }

        return $processed >= $count && $success;
    }

}
