<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentBranchCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('environment:branch')
            ->setAliases(['branch'])
            ->setDescription('Branch an environment')
            ->addArgument('id', InputArgument::OPTIONAL, 'The ID (branch name) of the new environment')
            ->addArgument('parent', InputArgument::OPTIONAL, 'The parent of the new environment')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'The title of the new environment')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'The type of the new environment')
            ->addOption('no-clone-parent', null, InputOption::VALUE_NONE, "Do not clone the parent environment's data")
            ->addHiddenOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run: do not create a new environment');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
        $this->addHiddenOption('force', null, InputOption::VALUE_NONE, 'Deprecated option, no longer used');
        $this->addHiddenOption('identity-file', 'i', InputOption::VALUE_REQUIRED, 'Deprecated option, no longer used');
        $this->addExample('Create a new branch "sprint-2", based on "develop"', 'sprint-2 develop');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->warnAboutDeprecatedOptions(['force', 'identity-file']);

        $this->envArgName = 'parent';
        $this->chooseEnvText = 'Enter a number to choose a parent environment:';
        $this->enterEnvText = 'Enter the ID of the parent environment';
        $branchName = $input->getArgument('id');
        $this->validateInput($input, $branchName === null);
        $selectedProject = $this->getSelectedProject();

        if ($branchName === null) {
            if ($input->isInteractive()) {
                // List environments.
                return $this->runOtherCommand(
                    'environments',
                    ['--project' => $selectedProject->id]
                );
            }
            $this->stdErr->writeln("<error>You must specify the name of the new branch.</error>");

            return 1;
        }

        $parentEnvironment = $this->getSelectedEnvironment();

        if ($branchName === $parentEnvironment->id && ($e = $this->getCurrentEnvironment($selectedProject)) && $e->id === $branchName) {
            $this->stdErr->writeln('Already on <comment>' . $branchName . '</comment>');
            return 1;
        }

        if ($environment = $this->api()->getEnvironment($branchName, $selectedProject)) {
            if (!$this->getProjectRoot()) {
                $this->stdErr->writeln("The environment <comment>$branchName</comment> already exists.");

                return 1;
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $checkout = $questionHelper->confirm(
                "The environment <comment>$branchName</comment> already exists. Check out?"
            );
            if ($checkout) {
                return $this->runOtherCommand(
                    'environment:checkout',
                    ['id' => $environment->id]
                );
            }

            return 1;
        }

        if (!$parentEnvironment->operationAvailable('branch', true)) {
            $this->stdErr->writeln(
                "Operation not available: The environment " . $this->api()->getEnvironmentLabel($parentEnvironment, 'error') . " can't be branched."
            );

            if ($parentEnvironment->is_dirty) {
                $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
            } elseif (!$parentEnvironment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            }

            return 1;
        }

        $title = $input->getOption('title') !== null ? $input->getOption('title') : $branchName;

        $newLabel = strlen($title) > 0 && $title !== $branchName
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
        $this->stdErr->writeln(sprintf($parentMessage, $this->api()->getEnvironmentLabel($parentEnvironment, 'info', false)));

        $dryRun = $input->getOption('dry-run');
        if ($dryRun) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<comment>Dry-run mode:</comment> skipping branch operation.');

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
            $result = $parentEnvironment->runOperation('branch', 'POST', $params);
            $activities = $result->getActivities();

            // Clear the environments cache, as branching has started.
            $this->api()->clearEnvironmentsCache($selectedProject->id);
        }

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');

        $createdNew = false;
        $projectRoot = $this->getProjectRoot();
        if ($projectRoot && !$dryRun) {
            // If the Git branch already exists locally, just check it out.
            $existsLocally = $git->branchExists($branchName, $projectRoot);
            if ($existsLocally) {
                $this->stdErr->writeln("Checking out <info>$branchName</info> locally");
                if (!$git->checkOut($branchName, $projectRoot)) {
                    $this->stdErr->writeln('Failed to check out branch locally: <error>' . $branchName . '</error>');
                }
            } else {
                // Create a new branch, using the parent if it exists locally.
                $parent = $git->branchExists($parentEnvironment->id, $projectRoot) ? $parentEnvironment->id : null;
                $this->stdErr->writeln("Creating local branch <info>$branchName</info>");

                if (!$git->checkOutNew($branchName, $parent, null, $projectRoot)) {
                    $this->stdErr->writeln('Failed to create branch locally: <error>' . $branchName . '</error>');
                }
                $createdNew = true;
            }
        } elseif (!$projectRoot) {
            $this->stdErr->writeln([
                '',
                'This command was run from outside a local project root, so the new branch cannot be checked out automatically.',
                sprintf(
                    'To switch to the branch when inside a repository run: <comment>%s checkout %s</comment>',
                    $this->config()->get('application.executable'),
                    OsUtil::escapeShellArg($branchName)
                ),
            ]);
        }

        $remoteSuccess = true;
        if ($this->shouldWait($input) && !$dryRun && $activities) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $remoteSuccess = $activityMonitor->waitMultiple($activities, $selectedProject);

            // If a new local branch has been created, set it to track the
            // remote branch. This requires first fetching the new branch from
            // the remote.
            if ($remoteSuccess && $projectRoot && $createdNew) {
                $upstreamRemote = $this->config()->get('detection.git_remote_name');
                $git->fetch($upstreamRemote, $branchName, $projectRoot);
                $git->setUpstream($upstreamRemote . '/' . $branchName, $branchName, $projectRoot);
            }

            $this->api()->clearEnvironmentsCache($selectedProject->id);
        }

        return $remoteSuccess ? 0 : 1;
    }
}
