<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\ResourcesUtil;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:activate', description: 'Activate an environment')]
class EnvironmentActivateCommand extends CommandBase
{
    /** @var string[] */
    private array $validResourcesInitOptions = ['parent', 'default', 'minimum'];

    public function __construct(
        private readonly ActivityMonitor $activityMonitor,
        private readonly Api             $api,
        private readonly Config          $config,
        private readonly QuestionHelper  $questionHelper,
        private readonly ResourcesUtil   $resourcesUtil,
        private readonly Selector        $selector,
        private readonly SubCommandRunner $subCommandRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::IS_ARRAY, 'The environment(s) to activate')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Set a new environment parent before activating');
        $this->resourcesUtil->addOption($this->getDefinition(), $this->validResourcesInitOptions);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addExample('Activate the environments "develop" and "stage"', 'develop stage');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));

        if ($selection->hasEnvironment()) {
            $toActivate = [$selection->getEnvironment()];
        } else {
            $environments = $this->api->getEnvironments($selection->getProject());
            $environmentIds = $input->getArgument('environment');
            $toActivate = array_intersect_key($environments, array_flip($environmentIds));
            $notFound = array_diff($environmentIds, array_keys($environments));
            foreach ($notFound as $notFoundId) {
                $this->stdErr->writeln("Environment not found: <error>$notFoundId</error>");
            }
        }

        $success = $this->activateMultiple($toActivate, $selection->getProject(), $input, $this->stdErr);

        return $success ? 0 : 1;
    }

    /**
     * @param Environment[] $environments
     */
    protected function activateMultiple(array $environments, Project $project, InputInterface $input, OutputInterface $output): bool
    {
        $parentId = $input->getOption('parent');
        if ($parentId && !$this->api->getEnvironment($parentId, $project)) {
            $this->stdErr->writeln(sprintf('Parent environment not found: <error>%s</error>', $parentId));
            return false;
        }

        // Validate the --resources-init option.
        $resourcesInit = $this->resourcesUtil->validateInput($input, $project, $this->validResourcesInitOptions);
        if ($resourcesInit === false) {
            return false;
        }

        $count = count($environments);
        $processed = 0;
        // Confirm which environments the user wishes to be activated.
        $process = [];
        foreach ($environments as $environment) {
            if (!$environment->operationAvailable('activate', true)) {
                if ($environment->isActive()) {
                    $output->writeln("The environment " . $this->api->getEnvironmentLabel($environment) . " is already active.");
                    $count--;
                    continue;
                }
                if ($environment->status === 'paused') {
                    $output->writeln("The environment " . $this->api->getEnvironmentLabel($environment, 'comment') . " is paused.");
                    if (count($environments) === 1 && $input->isInteractive() && $this->questionHelper->confirm('Do you want to resume it?')) {
                        return $this->subCommandRunner->run('environment:resume', [
                            '--project' => $environment->project,
                            '--environment' => $environment->id,
                            '--wait' => $input->getOption('wait'),
                            '--no-wait' => $input->getOption('no-wait'),
                            '--yes' => true,
                        ]) === 0;
                    }
                    $output->writeln(sprintf(
                        'To resume the environment, run: <comment>%s env:resume</comment>',
                        $this->config->getStr('application.executable'),
                    ));
                    $count--;
                    continue;
                }

                $output->writeln(
                    "Operation not available: The environment " . $this->api->getEnvironmentLabel($environment, 'error') . " can't be activated.",
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
                $hasGuaranteedCPU = $this->api->environmentHasGuaranteedCPU($environment, $project);
            } catch (EnvironmentStateException) {
                $hasGuaranteedCPU = false;
            }

            $question = "Are you sure you want to activate the environment " . $this->api->getEnvironmentLabel($environment) . "?";
            if ($resourcesInit === 'parent' && $hasGuaranteedCPU && $this->config->has('warnings.guaranteed_resources_branch_msg')) {
                $this->stdErr->writeln('');
                $question = trim($this->config->getStr('warnings.guaranteed_resources_branch_msg'))
                    . "\n\n" . $question;
            }

            if (!$this->questionHelper->confirm($question)) {
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
                        $parentId,
                    ));
                    $result = $environment->update(['parent' => $parentId]);
                    $activities = array_merge($activities, $result->getActivities());
                }
                $output->writeln(sprintf(
                    'Activating environment <info>%s</info>',
                    $environmentId,
                ));
                $activities = array_merge($activities, $environment->runOperation('activate', 'POST', $params)->getActivities());
                $processed++;
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }

        $success = $processed >= $count;

        if ($processed) {
            if ($this->activityMonitor->shouldWait($input)) {
                $activityMonitor = $this->activityMonitor;
                $result = $activityMonitor->waitMultiple($activities, $project);
                $success = $success && $result;
            }
            $this->api->clearEnvironmentsCache($project->id);
        }

        return $success;
    }
}
