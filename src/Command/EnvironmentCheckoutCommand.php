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

        $gitHelper = new GitHelper(new ShellHelper($output));
        $gitHelper->setDefaultRepositoryDir($projectRoot . '/' . LocalProject::REPOSITORY_DIR);

        $branch = $this->branchExists($specifiedBranch, $project, $gitHelper);

        if (!$branch) {
            $output->writeln("<error>Branch not found: $specifiedBranch</error>");

            return 1;
        }

        if (!$gitHelper->branchExists($branch)) {
            $gitHelper->execute(array('fetch', 'origin'));
        }

        // Check out the branch.
        $output->writeln("Checking out <info>$branch</info>");

        return $gitHelper->checkOut($branch) ? 0 : 1;
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
