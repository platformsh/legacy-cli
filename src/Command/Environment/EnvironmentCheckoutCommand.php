<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Service\Ssh;
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
        Ssh::configureInput($this->getDefinition());
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
        if ($branch === null) {
            if ($input->isInteractive()) {
                $branch = $this->offerBranchChoice($project, $projectRoot);
                if ($branch === false) {
                    return 1;
                }
            } else {
                $this->stdErr->writeln('No branch specified.');

                return 1;
            }
        }

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $git->setDefaultRepositoryDir($projectRoot);

        $existsLocally = $git->branchExists($branch);
        if (!$existsLocally && !$this->api()->getEnvironment($branch, $project)) {
            $this->stdErr->writeln('Branch not found: <error>' . $branch . '</error>');

            return 1;
        }

        // If the branch exists locally, check it out directly.
        if ($existsLocally) {
            $this->stdErr->writeln('Checking out <info>' . $branch . '</info>');

            return $git->checkOut($branch) ? 0 : 1;
        }

        // Make sure that remotes are set up correctly.
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $localProject->ensureGitRemote($projectRoot, $project->getGitUrl());

        // Determine the correct upstream for the new branch. If there is an
        // 'origin' remote, then it has priority.
        $upstreamRemote = $this->config()->get('detection.git_remote_name');
        $originRemoteUrl = $git->getConfig('remote.origin.url');
        if ($originRemoteUrl !== $project->getGitUrl() && $git->remoteBranchExists('origin', $branch)) {
            $upstreamRemote = 'origin';
        }

        // Fetch the branch from the upstream remote.
        $git->fetch($upstreamRemote, $branch, $originRemoteUrl);

        $upstream = $upstreamRemote . '/' . $branch;

        $this->stdErr->writeln(sprintf('Creating local branch %s based on upstream %s', $branch, $upstream));

        // Create the new branch, and set the correct upstream.
        $success = $git->checkOutNew($branch, null, $upstream);

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
            $this->stdErr->writeln("The current environment is " . $this->api()->getEnvironmentLabel($currentEnvironment) . ".");
            $this->stdErr->writeln('');
        }
        $environmentList = [];
        foreach ($environments as $id => $environment) {
            // The $id will be an integer for numeric environment names (as
            // it was assigned to an array key), so it's cast back to a
            // string for this comparison.
            if ($currentEnvironment && (string) $id === $currentEnvironment->id) {
                continue;
            }
            $environmentList[$id] = $this->api()->getEnvironmentLabel($environment, false);
        }
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $projectConfig = $localProject->getProjectConfig($projectRoot);
        if (!empty($projectConfig['mapping'])) {
            foreach ($projectConfig['mapping'] as $branch => $id) {
                if (isset($environmentList[$id]) && isset($environmentList[$branch])) {
                    unset($environmentList[$id]);
                    $environmentList[$branch] = sprintf('%s (%s)', $environments[$id]->title, $branch);
                }
            }
        }
        if (!count($environmentList)) {
            $this->stdErr->writeln(sprintf(
                'To create a new environment, run: <info>%s branch [new-branch]</info>',
                $this->config()->get('application.executable')
            ));

            return false;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $helper */
        $helper = $this->getService('question_helper');

        // If there's more than one choice, present the user with a list.
        if (count($environmentList) > 1) {
            $chooseEnvironmentText = "Enter a number to check out another environment:";

            // The environment ID will be an integer if it was numeric
            // (because PHP does that with array keys), so it's cast back to
            // a string here.
            return (string) $helper->choose($environmentList, $chooseEnvironmentText);
        }

        // If there's only one choice, QuestionHelper::choose() does not
        // interact. But we still need interactive confirmation at this point.
        $environmentId = key($environmentList);
        if ($environmentId !== false) {
            $label = $this->api()->getEnvironmentLabel($environments[$environmentId]);
            if ($helper->confirm(sprintf('Check out environment %s?', $label))) {
                return $environmentId;
            }
        }

        return false;
    }
}
