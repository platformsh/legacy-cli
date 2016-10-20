<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Util\ActivityUtil;
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
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                "Create the new environment even if the branch cannot be checked out locally"
            );
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addNoWaitOption("Do not wait for the environment to be branched");
        $this->addExample('Create a new branch "sprint-2", based on "develop"', 'sprint-2 develop');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->envArgName = 'parent';
        $this->validateInput($input, true);
        $selectedProject = $this->getSelectedProject();
        $parentEnvironment = $this->getSelectedEnvironment();

        $branchName = $input->getArgument('id');
        if (empty($branchName)) {
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

        if ($branchName === $parentEnvironment->id) {
            $this->stdErr->writeln('Already on <comment>' . $branchName . '</comment>');

            return 1;
        }

        if ($environment = $this->api()->getEnvironment($branchName, $selectedProject)) {
            $checkout = $this->getHelper('question')
                             ->confirm(
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

        if (!$parentEnvironment->operationAvailable('branch')) {
            $this->stdErr->writeln(
                "Operation not available: The environment <error>{$parentEnvironment->id}</error> can't be branched."
            );
            if ($parentEnvironment->is_dirty) {
                $this->api()->clearEnvironmentsCache($selectedProject->id);
            }

            return 1;
        }

        $force = $input->getOption('force');

        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot && $force) {
            $this->stdErr->writeln(
                "<comment>This command was run from outside your local project root, so the new " . self::$config->get('service.name') . " branch cannot be checked out in your local Git repository."
                . " Make sure to run '" . self::$config->get('application.executable') . " checkout' or 'git checkout' in your local repository to switch to the branch you are expecting.</comment>"
            );
        } elseif (!$projectRoot) {
            $this->stdErr->writeln("<error>You must run this command inside the project root, or specify --force.</error>");

            return 1;
        }

        $this->stdErr->writeln(
            "Creating a new environment <info>$branchName</info>, branched from <info>{$parentEnvironment->title}</info>"
        );

        $title = $input->getOption('title') ?: $branchName;
        $activity = $parentEnvironment->branch($title, $branchName);

        // Clear the environments cache, as branching has started.
        $this->api()->clearEnvironmentsCache($selectedProject->id);

        if ($projectRoot) {
            $gitHelper = new GitHelper(new ShellHelper($this->stdErr));
            $gitHelper->setDefaultRepositoryDir($projectRoot);

            // If the Git branch already exists locally, just check it out.
            $existsLocally = $gitHelper->branchExists($branchName);
            if ($existsLocally) {
                $this->stdErr->writeln("Checking out <info>$branchName</info> locally");
                if (!$gitHelper->checkOut($branchName)) {
                    $this->stdErr->writeln('<error>Failed to check out branch locally: ' . $branchName . '</error>');
                    if (!$force) {
                        return 1;
                    }
                }
            } else {
                // Create a new branch, using the parent if it exists locally.
                $parent = $gitHelper->branchExists($parentEnvironment->id) ? $parentEnvironment->id : null;
                $this->stdErr->writeln("Creating local branch <info>$branchName</info>");

                if (!$gitHelper->checkOutNew($branchName, $parent)) {
                    $this->stdErr->writeln('<error>Failed to create branch locally: ' . $branchName . '</error>');
                    if (!$force) {
                        return 1;
                    }
                }
            }
        }

        $remoteSuccess = true;
        if (!$input->getOption('no-wait')) {
            $remoteSuccess = ActivityUtil::waitAndLog(
                $activity,
                $this->stdErr,
                "The environment <info>$branchName</info> has been created.",
                '<error>Branching failed</error>'
            );

            if ($remoteSuccess && $projectRoot) {
                // Determine the correct upstream for the new branch. If there is an
                // 'origin' remote, then it has priority.
                $upstreamRemote = self::$config->get('detection.git_remote_name');
                $originRemoteUrl = $gitHelper->getConfig('remote.origin.url');
                if ($originRemoteUrl !== $selectedProject->getGitUrl(false) && $gitHelper->remoteBranchExists('origin', $branchName)) {
                    $upstreamRemote = 'origin';
                }

                // Fetch the branch from the upstream remote.
                $gitHelper->fetch($upstreamRemote, $branchName);

                $upstream = $upstreamRemote . '/' . $branchName;

                $gitHelper->setUpstream($upstream);
            }
        }

        $this->api()->clearEnvironmentsCache($this->getSelectedProject()->id);

        return $remoteSuccess ? 0 : 1;
    }
}
