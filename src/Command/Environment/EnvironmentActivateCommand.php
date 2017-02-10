<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentActivateCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:activate')
            ->setDescription('Activate an environment')
            ->addArgument('environment', InputArgument::IS_ARRAY, 'The environment(s) to activate')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Set a new environment parent before activating');
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
        $parentId = $input->getOption('parent');
        if ($parentId && !$this->api()->getEnvironment($parentId, $this->getSelectedProject())) {
            $this->stdErr->writeln(sprintf('Parent environment not found: <error>%s</error>', $parentId));
            return false;
        }

        $count = count($environments);
        $processed = 0;
        // Confirm which environments the user wishes to be activated.
        $process = [];
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
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
                if ($parentId && $parentId !== $environment->parent && $parentId !== $environmentId) {
                    $output->writeln(sprintf(
                        'Setting parent of environment <info>%s</info> to <info>%s</info>',
                        $environmentId,
                        $parentId
                    ));
                    $result = $environment->update(['parent' => $parentId]);
                    $activities = array_merge($activities, $result->getActivities());
                }
                $output->writeln(sprintf(
                    'Activating environment <info>%s</info>',
                    $environmentId
                ));
                $activities[] = $environment->activate();
                $processed++;
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }

        $success = $processed >= $count;

        if ($processed) {
            if (!$input->getOption('no-wait')) {
                /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
                $activityMonitor = $this->getService('activity_monitor');
                $result = $activityMonitor->waitMultiple($activities, $this->getSelectedProject());
                $success = $success && $result;
            }
            $this->api()->clearEnvironmentsCache($this->getSelectedProject()->id);
        }

        return $success;
    }
}
