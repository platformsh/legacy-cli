<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Exception\EnvironmentStateException;
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
        $this->addResourcesInitOption(['parent', 'default', 'minimum']);
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
        $this->addExample('Activate the environments "develop" and "stage"', 'develop stage');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->chooseEnvFilter = $this->filterEnvsByStatus(['inactive', 'paused']);
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

        // Validate the --resources-init option.
        $resourcesInit = $this->validateResourcesInitInput($input, $this->getSelectedProject());
        if ($resourcesInit === false) {
            return 1;
        }

        $count = count($environments);
        $processed = 0;
        // Confirm which environments the user wishes to be activated.
        $process = [];
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        foreach ($environments as $environment) {
            if (!$environment->operationAvailable('activate', true)) {
                if ($environment->isActive()) {
                    $output->writeln("The environment " . $this->api()->getEnvironmentLabel($environment) . " is already active.");
                    $count--;
                    continue;
                }
                if ($environment->status === 'paused') {
                    $output->writeln("The environment " . $this->api()->getEnvironmentLabel($environment, 'comment') . " is paused.");
                    if (count($environments) === 1 && $input->isInteractive() && $questionHelper->confirm('Do you want to resume it?')) {
                        return $this->runOtherCommand('environment:resume', [
                            '--project' => $environment->project,
                            '--environment' => $environment->id,
                            '--wait' => $input->getOption('wait'),
                            '--no-wait' => $input->getOption('no-wait'),
                            '--yes' => true,
                        ]);
                    }
                    $output->writeln(sprintf(
                        'To resume the environment, run: <comment>%s env:resume</comment>',
                        $this->config()->get('application.executable')
                    ));
                    $count--;
                    continue;
                }

                $output->writeln(
                    "Operation not available: The environment " . $this->api()->getEnvironmentLabel($environment, 'error') . " can't be activated."
                );
                if ($environment->is_main && !$environment->has_code) {
                    $output->writeln('');
                    $output->writeln('The environment has no code yet. Push some code to the environment to activate it.');
                } elseif ($environment->is_dirty) {
                    $output->writeln('');
                    $output->writeln('An activity is currently in progress on the environment.');
                }
                continue;
            }

            try {
                $hasGuaranteedCPU = $this->api()->environmentHasGuaranteedCPU($environment);
            } catch (EnvironmentStateException $e) {
                $hasGuaranteedCPU = false;
            }

            $question = "Are you sure you want to activate the environment " . $this->api()->getEnvironmentLabel($environment) . "?";
            if ($resourcesInit === 'parent' && $hasGuaranteedCPU && $this->config()->has('warnings.guaranteed_resources_branch_msg')) {
                $this->stdErr->writeln('');
                $question = trim($this->config()->get('warnings.guaranteed_resources_branch_msg'))
                    . "\n\n" . $question;
            }

            if (!$questionHelper->confirm($question)) {
                continue;
            }
            $process[$environment->id] = $environment;
        }

        $params = [];
        if ($resourcesInit !== null) {
            $params['resources']['init'] = $resourcesInit;
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
                $activities = array_merge($activities, $environment->runOperation('activate', 'POST', $params)->getActivities());
                $processed++;
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }

        $success = $processed >= $count;

        if ($processed) {
            if ($this->shouldWait($input)) {
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
