<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
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
        $this->addExample('Check out the environment "develop"', 'develop');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->getCurrentProject();
        $projectRoot = $this->getProjectRoot();
        if (!$project || !$projectRoot) {
            throw new RootNotFoundException();
        }

        $branch = $input->getArgument('id');
        if (empty($branch)) {
            if ($input->isInteractive()) {
                $branch = $this->offerBranchChoice($project, $projectRoot);
                if (empty($branch)) {
                    return 1;
                }
            }
            else {
                $this->stdErr->writeln('No branch specified.');

                return 1;
            }
        }

        /** @var \Platformsh\Cli\Helper\GitHelper $gitHelper */
        $gitHelper = $this->getHelper('git');
        $gitHelper->setDefaultRepositoryDir($projectRoot);

        $existsLocally = $gitHelper->branchExists($branch);
        if (!$existsLocally && !$this->api()->getEnvironment($branch, $project)) {
            $this->stdErr->writeln('Branch not found: <error>' . $branch . '</error>');

            return 1;
        }

        // If the branch exists locally, check it out directly.
        if ($existsLocally) {
            $this->stdErr->writeln('Checking out <info>' . $branch . '</info>');

            return $gitHelper->checkOut($branch) ? 0 : 1;
        }

        // Make sure that remotes are set up correctly.
        $this->localProject->ensureGitRemote($projectRoot, $project->getGitUrl());

        // Determine the correct upstream for the new branch. If there is an
        // 'origin' remote, then it has priority.
        $upstreamRemote = self::$config->get('detection.git_remote_name');
        $originRemoteUrl = $gitHelper->getConfig('remote.origin.url');
        if ($originRemoteUrl !== $project->getGitUrl(false) && $gitHelper->remoteBranchExists('origin', $branch)) {
            $upstreamRemote = 'origin';
        }

        // Fetch the branch from the upstream remote.
        $gitHelper->fetch($upstreamRemote, $branch);

        $upstream = $upstreamRemote . '/' . $branch;

        $this->stdErr->writeln(sprintf('Creating branch %s based on upstream %s', $branch, $upstream));

        // Create the new branch, and set the correct upstream.
        $success = $gitHelper->checkOutNew($branch, null, $upstream);

        return $success ? 0 : 1;
    }

    /**
     * Prompt the user to select a branch to checkout.
     *
     * @param Project $project
     * @param string  $projectRoot
     *
     * @return string|false
     *   The branch name, or false on failure.
     */
    protected function offerBranchChoice(Project $project, $projectRoot)
    {
        $environments = $this->api()->getEnvironments($project);
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
        $projectConfig = $this->localProject->getProjectConfig($projectRoot);
        if (!empty($projectConfig['mapping'])) {
            foreach ($projectConfig['mapping'] as $branch => $id) {
                if (isset($environmentList[$id]) && isset($environmentList[$branch])) {
                    unset($environmentList[$id]);
                    $environmentList[$branch] = sprintf('%s (%s)', $environments[$id]->title, $branch);
                }
            }
        }
        if (!count($environmentList)) {
            $this->stdErr->writeln("Use <info>" . self::$config->get('application.executable') . " branch</info> to create an environment.");

            return false;
        }

        /** @var \Platformsh\Cli\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');

        // If there's more than one choice, present the user with a list.
        if (count($environmentList) > 1) {
            $chooseEnvironmentText = "Enter a number to check out another environment:";
            return $helper->choose($environmentList, $chooseEnvironmentText);
        }
        // If there's only one choice, QuestionHelper::choose() does not
        // interact. But we still need interactive confirmation at this
        // point.
        elseif ($helper->confirm(sprintf('Check out environment <info>%s</info>?', reset($environmentList)))) {
            return key($environmentList);
        }

        return false;
    }

}
