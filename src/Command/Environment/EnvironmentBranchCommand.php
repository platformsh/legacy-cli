<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
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
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                "Create the new environment even if the branch cannot be checked out locally"
            )
            ->addOption(
                'no-clone-parent',
                null,
                InputOption::VALUE_NONE,
                "Do not clone the parent branch's data"
            );
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Create a new branch "sprint-2", based on "develop"', 'sprint-2 develop');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->envArgName = 'parent';
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

        if ($branchName === $parentEnvironment->id) {
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

        $force = $input->getOption('force');

        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot && $force) {
            $this->stdErr->writeln(
                "<comment>This command was run from outside your local project root, so the new " . $this->config()->get('service.name') . " branch cannot be checked out in your local Git repository."
                . " Make sure to run '" . $this->config()->get('application.executable') . " checkout' or 'git checkout' in your local repository to switch to the branch you are expecting.</comment>"
            );
        } elseif (!$projectRoot) {
            $this->stdErr->writeln(
                '<error>You must run this command inside the project root, or specify --force.</error>'
            );

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

        $this->stdErr->writeln(sprintf(
            'Creating a new environment %s, branched from %s',
            $newLabel,
            $this->api()->getEnvironmentLabel($parentEnvironment)
        ));

        $activity = $parentEnvironment->branch(
            $title,
            $branchName,
            !$input->getOption('no-clone-parent'),
            $type
        );

        // Clear the environments cache, as branching has started.
        $this->api()->clearEnvironmentsCache($selectedProject->id);

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');

        $createdNew = false;
        if ($projectRoot) {
            // If the Git branch already exists locally, just check it out.
            $existsLocally = $git->branchExists($branchName, $projectRoot);
            if ($existsLocally) {
                $this->stdErr->writeln("Checking out <info>$branchName</info> locally");
                if (!$git->checkOut($branchName, $projectRoot)) {
                    $this->stdErr->writeln('<error>Failed to check out branch locally: ' . $branchName . '</error>');
                    if (!$force) {
                        return 1;
                    }
                }
            } else {
                // Create a new branch, using the parent if it exists locally.
                $parent = $git->branchExists($parentEnvironment->id, $projectRoot) ? $parentEnvironment->id : null;
                $this->stdErr->writeln("Creating local branch <info>$branchName</info>");

                if (!$git->checkOutNew($branchName, $parent, null, $projectRoot)) {
                    $this->stdErr->writeln('<error>Failed to create branch locally: ' . $branchName . '</error>');
                    if (!$force) {
                        return 1;
                    }
                }
                $createdNew = true;
            }
        }

        $remoteSuccess = true;
        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $remoteSuccess = $activityMonitor->waitAndLog($activity);

            // If a new local branch has been created, set it to track the
            // remote branch. This requires first fetching the new branch from
            // the remote.
            if ($remoteSuccess && $projectRoot && $createdNew) {
                $upstreamRemote = $this->config()->get('detection.git_remote_name');
                $git->fetch($upstreamRemote, $branchName, $projectRoot);
                $git->setUpstream($upstreamRemote . '/' . $branchName, $branchName, $projectRoot);
            }
        }

        $this->api()->clearEnvironmentsCache($this->getSelectedProject()->id);

        return $remoteSuccess ? 0 : 1;
    }
}
