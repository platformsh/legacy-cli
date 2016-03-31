<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentCheckoutCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:checkout')
            ->setAliases(['checkout'])
            ->setDescription('Check out an environment')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'The ID of the environment to check out. For example: "sprint2"'
            );
        $this->addExample('Check out the environment "develop"', 'master');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->getCurrentProject();
        if (!$project) {
            throw new RootNotFoundException();
        }

        $specifiedBranch = $input->getArgument('id');
        if (empty($specifiedBranch) && $input->isInteractive()) {
            $environments = $this->getEnvironments($project);
            $currentEnvironment = $this->getCurrentEnvironment($project);
            if ($currentEnvironment) {
                $this->stdErr->writeln("The current environment is <info>{$currentEnvironment->title}</info>.");
            }
            $environmentList = [];
            foreach ($environments as $id => $environment) {
                if ($currentEnvironment && $id == $currentEnvironment->id) {
                    continue;
                }
                $environmentList[$id] = $environment->title;
            }
            $config = $this->getProjectConfig($this->getProjectRoot());
            if (!empty($config['mapping'])) {
                foreach ($config['mapping'] as $branch => $id) {
                    if (isset($environmentList[$id]) && isset($environmentList[$branch])) {
                        unset($environmentList[$id]);
                        $environmentList[$branch] = sprintf('%s (%s)', $environments[$id]->title, $branch);
                    }
                }
            }
            if (!count($environmentList)) {
                $this->stdErr->writeln("Use <info>" . CLI_EXECUTABLE . " branch</info> to create an environment.");

                return 1;
            }
            /** @var \Platformsh\Cli\Helper\QuestionHelper $helper */
            $helper = $this->getHelper('question');
            // If there's more than one choice, present the user with a list.
            if (count($environmentList) > 1) {
                $chooseEnvironmentText = "Enter a number to check out another environment:";
                $specifiedBranch = $helper->choose($environmentList, $chooseEnvironmentText);
            }
            // If there's only one choice, QuestionHelper::choose() does not
            // interact. But we still need interactive confirmation at this
            // point.
            elseif ($helper->confirm(sprintf('Check out environment <info>%s</info>?', reset($environmentList)))) {
                $specifiedBranch = key($environmentList);
            }
            else {
                return 1;
            }
        } elseif (empty($specifiedBranch)) {
            $this->stdErr->writeln("<error>No branch specified.</error>");

            return 1;
        }

        $projectRoot = $this->getProjectRoot();

        $gitHelper = new GitHelper(new ShellHelper($this->stdErr));
        $gitHelper->setDefaultRepositoryDir($projectRoot);

        $branch = $this->branchExists($specifiedBranch, $project, $gitHelper);

        if (!$branch) {
            $this->stdErr->writeln("<error>Branch not found: $specifiedBranch</error>");

            return 1;
        }

        // If the branch exists locally, check it out directly.
        if ($gitHelper->branchExists($branch)) {
            $this->stdErr->writeln("Checking out <info>$branch</info>");

            return $gitHelper->checkOut($branch) ? 0 : 1;
        }

        // Make sure that remotes are set up correctly.
        $this->localProject->ensureGitRemote($projectRoot, $project->getGitUrl());

        // Determine the correct upstream for the new branch. If there is an
        // 'origin' remote, then it has priority.
        $upstreamRemote = CLI_GIT_REMOTE_NAME;
        if ($gitHelper->getConfig('remote.origin.url') && $gitHelper->remoteBranchExists('origin', $branch)) {
            $upstreamRemote = 'origin';
        }

        $this->stdErr->writeln("Creating branch $branch based on upstream $upstreamRemote/$branch");

        // Fetch the branch from the upstream remote.
        $gitHelper->execute(['fetch', $upstreamRemote, $branch]);

        // Create the new branch, and set the correct upstream.
        $success = $gitHelper->checkOutNew($branch, $upstreamRemote . '/' . $branch);

        return $success ? 0 : 1;
    }

    /**
     * Check whether a branch exists, locally in Git or on the remote.
     *
     * @param string    $branch
     * @param Project   $project
     * @param GitHelper $gitHelper
     *
     * @return string|false
     */
    protected function branchExists($branch, Project $project, GitHelper $gitHelper)
    {
        // Check if the Git branch exists locally.
        $candidates = array_unique([Environment::sanitizeId($branch), $branch]);
        foreach ($candidates as $candidate) {
            if ($gitHelper->branchExists($candidate)) {
                return $candidate;
            }
        }
        // Check if the environment exists by title or ID. This is usually faster
        // than running 'git ls-remote'.
        $environments = $this->getEnvironments($project);
        foreach ($environments as $environment) {
            if ($environment->title == $branch || $environment->id == $branch) {
                return $environment->id;
            }
        }

        return false;
    }

}
