<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentCheckoutCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('environment:checkout')
          ->setAliases(array('checkout'))
          ->setDescription('Check out an environment')
          ->addArgument(
            'id',
            InputArgument::OPTIONAL,
            'The ID of the environment to check out. For example: "sprint2"'
          );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->getCurrentProject();
        if (!$project) {
            throw new \Exception('This can only be run from inside a project directory');
        }

        $specifiedBranch = $input->getArgument('id');
        if (empty($specifiedBranch) && $input->isInteractive()) {
            $environments = $this->getEnvironments($project);
            $currentEnvironment = $this->getCurrentEnvironment($project);
            if ($currentEnvironment) {
                $output->writeln("The current environment is <info>{$currentEnvironment['title']}</info>.");
            }
            $environmentList = array();
            foreach ($environments as $id => $environment) {
                if ($currentEnvironment && $id == $currentEnvironment['id']) {
                    continue;
                }
                $environmentList[$id] = $environment['title'];
            }
            if (!count($environmentList)) {
                $output->writeln("Use <info>platform branch</info> to create an environment.");

                return 1;
            }
            $chooseEnvironmentText = "Enter a number to check out another environment:";
            $helper = $this->getHelper('question');
            $specifiedBranch = $helper->choose($environmentList, $chooseEnvironmentText, $input, $output);
        } elseif (empty($specifiedBranch)) {
            $output->writeln("<error>No branch specified.</error>");

            return 1;
        }

        $projectRoot = $this->getProjectRoot();
        $repositoryDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;

        $gitHelper = new GitHelper(new ShellHelper($output));
        $gitHelper->setDefaultRepositoryDir($repositoryDir);

        $branch = $this->branchExists($specifiedBranch, $project, $gitHelper);

        if (!$branch) {
            $output->writeln("<error>Branch not found: $specifiedBranch</error>");

            return 1;
        }

        // If the branch exists locally, check it out directly.
        if ($gitHelper->branchExists($branch)) {
            $output->writeln("Checking out <info>$branch</info>");

            return $gitHelper->checkOut($branch) ? 0 : 1;
        }

        $verbose = $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;

        // Make sure that remotes are set up correctly.
        $localProject = new LocalProject();
        $localProject->ensureGitRemote($repositoryDir, $project->getGitUrl());

        $originUrl = $gitHelper->getConfig('remote.origin.url');
        $platformUrl = $gitHelper->getConfig('remote.platform.url');

        // Determine the correct upstream for the new branch. The origin has
        // priority, if the branch exists there.
        $upstreamRemote = 'platform';
        $upstreamRemoteUrl = $platformUrl;
        $inOrigin = $originUrl && $gitHelper->remoteBranchExists('origin', $branch);
        if ($inOrigin) {
            $upstreamRemote = 'origin';
            $upstreamRemoteUrl = $originUrl;
        }

        $output->writeln("Creating branch $branch based on upstream $upstreamRemote/$branch");

        // More than one remote with the same content can cause trouble with
        // tracking.
        $duplicateRemotes = $this->getDuplicateRemotes($upstreamRemote, $upstreamRemoteUrl, $gitHelper);
        foreach (array_keys($duplicateRemotes) as $duplicateRemote) {
            $gitHelper->execute(array('remote', 'rm', $duplicateRemote));
        }

        // Fetch the branch from the upstream remote.
        $gitHelper->execute(array('fetch', $upstreamRemote, $branch));

        // Create the new branch, and set the correct upstream.
        $success = $gitHelper->checkoutNew($branch, $upstreamRemote . '/' . $branch);

        // Restore the temporarily deleted duplicate remotes, if any.
        foreach ($duplicateRemotes as $duplicateRemote => $duplicateRemoteUrl) {
            $gitHelper->execute(array('remote', 'add', $duplicateRemote, $duplicateRemoteUrl));
        }

        return $success ? 0 : 1;
    }

    /**
     * Get a list of remotes matching the URL of the given remote.
     *
     * @param string    $givenRemote
     * @param string    $givenRemoteUrl
     * @param GitHelper $gitHelper
     *
     * @return array
     *   An array of duplicate remotes. The keys are the remote names, and the
     *   values are the remote URLs.
     */
    protected function getDuplicateRemotes($givenRemote, $givenRemoteUrl, GitHelper $gitHelper)
    {
        $matching = array();
        $remotes = explode("\n", $gitHelper->execute(array('remote')));
        foreach ($remotes as $remote) {
            if ($remote == $givenRemote) {
                continue;
            }
            $url = $gitHelper->getConfig("remote.$remote.url");
            if ($url === $givenRemoteUrl) {
                $matching[$remote] = $url;
            }
        }

        return $matching;
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
        $candidates = array_unique(array(Environment::sanitizeId($branch), $branch));
        foreach ($candidates as $candidate) {
            if ($gitHelper->branchExists($candidate)) {
                return $candidate;
            }
        }
        // Check if the environment exists by title or ID. This is usually faster
        // than running 'git ls-remote'.
        $environments = $this->getEnvironments($project);
        foreach ($environments as $environment) {
            if ($environment['title'] == $branch || $environment['id'] == $branch) {
                return $environment['id'];
            }
        }

        return false;
    }

}
