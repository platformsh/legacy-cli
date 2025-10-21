<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Service\ResourcesUtil;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:branch', description: 'Branch an environment', aliases: ['branch'])]
class EnvironmentBranchCommand extends CommandBase
{
    /** @var string[] */
    private array $validResourcesInitOptions = ['parent', 'default', 'minimum'];

    public function __construct(
        private readonly ActivityMonitor  $activityMonitor,
        private readonly Api              $api,
        private readonly Config           $config,
        private readonly Git              $git,
        private readonly Io               $io,
        private readonly QuestionHelper   $questionHelper,
        private readonly ResourcesUtil    $resourcesUtil,
        private readonly Selector         $selector,
        private readonly SubCommandRunner $subCommandRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'The ID (branch name) of the new environment')
            ->addArgument('parent', InputArgument::OPTIONAL, 'The parent of the new environment')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'The title of the new environment')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'The type of the new environment')
            ->addOption('no-clone-parent', null, InputOption::VALUE_NONE, "Do not clone the parent environment's data")
            ->addOption('no-checkout', null, InputOption::VALUE_NONE, 'Do not check out the branch locally')
            ->addHiddenOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run: do not create a new environment');
        $this->resourcesUtil->addOption($this->getDefinition(), $this->validResourcesInitOptions);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addHiddenOption('force', null, InputOption::VALUE_NONE, 'Deprecated option, no longer used');
        $this->addHiddenOption('identity-file', 'i', InputOption::VALUE_REQUIRED, 'Deprecated option, no longer used');
        $this->addExample('Create a new branch "sprint-2", based on "develop"', 'sprint-2 develop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->warnAboutDeprecatedOptions(['force', 'identity-file']);

        $branchName = $input->getArgument('id');
        $selectorConfig = new SelectorConfig(
            envRequired: $branchName !== null,
            envArgName: 'parent',
            chooseEnvText: 'Enter a number to choose a parent environment:',
            enterEnvText: 'Enter the ID of the parent environment',
            chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive(),
        );
        $selection = $this->selector->getSelection($input, $selectorConfig);
        $selectedProject = $selection->getProject();

        if ($branchName === null) {
            if ($input->isInteractive()) {
                // List environments.
                return $this->subCommandRunner->run(
                    'environments',
                    ['--project' => $selectedProject->id],
                );
            }
            $this->stdErr->writeln("<error>You must specify the name of the new branch.</error>");

            return 1;
        }

        $parentEnvironment = $selection->getEnvironment();

        if ($branchName === $parentEnvironment->id && ($e = $this->selector->getCurrentEnvironment($selectedProject)) && $e->id === $branchName) {
            $this->stdErr->writeln('Already on <comment>' . $branchName . '</comment>');
            return 1;
        }

        $projectRoot = $this->selector->getProjectRoot();
        $dryRun = $input->getOption('dry-run');
        $checkoutLocally = $projectRoot && !$input->getOption('no-checkout');

        if ($environment = $this->api->getEnvironment($branchName, $selectedProject)) {
            if (!$checkoutLocally || $dryRun) {
                $this->stdErr->writeln("The environment <comment>$branchName</comment> already exists.");

                return 1;
            }
            $checkout = $this->questionHelper->confirm(
                "The environment <comment>$branchName</comment> already exists. Check out?",
            );
            if ($checkout) {
                return $this->subCommandRunner->run(
                    'environment:checkout',
                    ['id' => $environment->id],
                );
            }

            return 1;
        }

        if (!$parentEnvironment->operationAvailable('branch', true)) {
            $this->stdErr->writeln(
                "Operation not available: The environment " . $this->api->getEnvironmentLabel($parentEnvironment, 'error', false) . " can't be branched.",
            );

            if ($parentEnvironment->getProperty('has_remote', false) === true
                && ($integration = $this->api->getCodeSourceIntegration($selection->getProject()))
                && $integration->getProperty('prune_branches', false) === true) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf("The project's branches are managed externally through its <info>%s</info> integration.", $integration->type));
                if ($this->config->isCommandEnabled('integration:get')) {
                    $this->stdErr->writeln(sprintf('To view the integration, run: <info>%s integration:get %s</info>', $this->config->getStr('application.executable'), OsUtil::escapeShellArg($integration->id)));
                }
            } elseif ($parentEnvironment->is_dirty) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
            } elseif (!$parentEnvironment->isActive()) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('The environment is not active.');
            }

            return 1;
        }

        // Validate the --resources-init option.
        $resourcesInit = $this->resourcesUtil->validateInput($input, $selectedProject, $this->validResourcesInitOptions);
        if ($resourcesInit === false) {
            return 1;
        }

        $title = $input->getOption('title') !== null ? $input->getOption('title') : $branchName;

        $newLabel = strlen((string) $title) > 0 && $title !== $branchName
            ? '<info>' . $title . '</info> (' . $branchName . ')'
            : '<info>' . $branchName . '</info>';

        $type = $input->getOption('type');
        if ($type !== null) {
            $newLabel .= ' (type: <info>' . $type . '</info>)';
        }

        $this->stdErr->writeln(sprintf('Creating a new environment: %s', $newLabel));
        $this->stdErr->writeln('');

        $parentMessage = $input->getOption('no-clone-parent')
            ? 'Settings will be copied from the parent environment: %s'
            : 'Settings will be copied and data cloned from the parent environment: %s';
        $this->stdErr->writeln(sprintf($parentMessage, $this->api->getEnvironmentLabel($parentEnvironment, 'info', false)));

        if ($resourcesInit === 'parent') {
            $this->stdErr->writeln('Resource sizes will be inherited from the parent environment.');
        }

        $hasGuaranteedCPU = $this->api->environmentHasGuaranteedCPU($parentEnvironment, $selectedProject);
        if ($resourcesInit === 'parent' && $hasGuaranteedCPU && $this->config->has('warnings.guaranteed_resources_branch_msg')) {
            $this->stdErr->writeln('');
            $questionText = trim($this->config->getStr('warnings.guaranteed_resources_branch_msg'))
                . "\n\n" . "Are you sure you want to continue?";

            if (!$this->questionHelper->confirm($questionText)) {
                return 1;
            }
        }

        if ($dryRun) {
            $this->stdErr->writeln('');
            if ($checkoutLocally) {
                $this->stdErr->writeln('<comment>Dry-run mode:</comment> skipping branching and local checkout.');
                $checkoutLocally = false;
            } else {
                $this->stdErr->writeln('<comment>Dry-run mode:</comment> skipping branch operation.');
            }

            $activities = [];
        } else {
            $params = [
                'name' => $branchName,
                'title' => $title,
                'clone_parent' => !$input->getOption('no-clone-parent'),
            ];
            if ($type !== null) {
                $params['type'] = $type;
            }
            if ($resourcesInit !== null) {
                $params['resources']['init'] = $resourcesInit;
            }

            $result = $parentEnvironment->runOperation('branch', 'POST', $params);
            $activities = $result->getActivities();

            // Clear the environments cache, as branching has started.
            $this->api->clearEnvironmentsCache($selectedProject->id);
        }

        $createdNew = false;
        if ($checkoutLocally) {
            /** @var string $projectRoot */
            if ($this->git->branchExists($branchName, $projectRoot)) {
                $this->stdErr->writeln("Checking out <info>$branchName</info> locally");
                if (!$this->git->checkOut($branchName, $projectRoot)) {
                    $this->stdErr->writeln('Failed to check out branch locally: <error>' . $branchName . '</error>');
                }
            } else {
                // Create a new branch, using the parent if it exists locally.
                $parent = $this->git->branchExists($parentEnvironment->id, $projectRoot) ? $parentEnvironment->id : null;
                $this->stdErr->writeln("Creating local branch <info>$branchName</info>");

                if (!$this->git->checkOutNew($branchName, $parent, null, $projectRoot)) {
                    $this->stdErr->writeln('Failed to create branch locally: <error>' . $branchName . '</error>');
                }
                $createdNew = true;
            }
        }

        $remoteSuccess = true;
        if ($this->activityMonitor->shouldWait($input) && !$dryRun && $activities) {
            $activityMonitor = $this->activityMonitor;
            $remoteSuccess = $activityMonitor->waitMultiple($activities, $selectedProject);
            $this->api->clearEnvironmentsCache($selectedProject->id);
        }

        // If a new local branch has been created, set its upstream.
        //
        // This will only be done if the repository already has a named remote,
        // matching the configured detection.git_remote_name, and set to the
        // project's Git URL.
        if ($remoteSuccess && $checkoutLocally && $createdNew) {
            $gitUrl = $selectedProject->getGitUrl();
            $remoteName = $this->config->getStr('detection.git_remote_name');
            /** @var string $projectRoot */
            if ($gitUrl && $this->git->getConfig(sprintf('remote.%s.url', $remoteName), $projectRoot) === $gitUrl) {
                $this->stdErr->writeln(sprintf(
                    'Setting the upstream for the local branch to: <info>%s/%s</info>',
                    $remoteName,
                    $branchName,
                ));
                if ($this->git->fetch($remoteName, $branchName, $gitUrl, $projectRoot)) {
                    $this->git->setUpstream($remoteName . '/' . $branchName, $branchName, $projectRoot);
                }
            }
        }

        return $remoteSuccess ? 0 : 1;
    }
}
