<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\LocalProject;
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
            if (!count($environmentList)) {
                $this->stdErr->writeln("Use <info>platform branch</info> to create an environment.");

                return 1;
            }
            $chooseEnvironmentText = "Enter a number to check out another environment:";
            $helper = $this->getHelper('question');
            $specifiedBranch = $helper->choose($environmentList, $chooseEnvironmentText, $input, $output);
        } elseif (empty($specifiedBranch)) {
            $this->stdErr->writeln("<error>No branch specified.</error>");

            return 1;
        }

        $projectRoot = $this->getProjectRoot();
        $repositoryDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;

        $gitHelper = new GitHelper(new ShellHelper($this->stdErr));
        $gitHelper->setDefaultRepositoryDir($repositoryDir);

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
        $localProject = new LocalProject();
        $localProject->ensureGitRemote($repositoryDir, $project->getGitUrl());

        // Determine the correct upstream for the new branch. If there is an
        // 'origin' remote, then it has priority.
        $upstreamRemote = 'platform';
        if ($gitHelper->getConfig('remote.origin.url') && $gitHelper->remoteBranchExists('origin', $branch)) {
            $upstreamRemote = 'origin';
        }

        $this->stdErr->writeln("Creating branch $branch based on upstream $upstreamRemote/$branch");

        // Fetch the branch from the upstream remote.
        $gitHelper->execute(['fetch', $upstreamRemote, $branch]);

        // Create the new branch, and set the correct upstream.
        $success = $gitHelper->checkoutNew($branch, $upstreamRemote . '/' . $branch);

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
