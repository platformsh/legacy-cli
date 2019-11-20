<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectSetRemoteCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('project:set-remote')
            ->setDescription('Set the remote project for the current Git repository')
            ->addArgument('project', InputArgument::OPTIONAL, 'The project ID');
        $this->addExample('Set the remote project for this repository to "abcdef123456"', 'abcdef123456');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectId = $input->getArgument('project');

        if ($projectId) {
            /** @var \Platformsh\Cli\Service\Identifier $identifier */
            $identifier = $this->getService('identifier');
            $result = $identifier->identify($projectId);
            $projectId = $result['projectId'];
        }

        $project = $this->selectProject($projectId, null, false);

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $root = $git->getRoot(getcwd());
        if ($root === false) {
            $this->stdErr->writeln(
                'No Git repository found. Use <info>git init</info> to create a repository.'
            );

            return 1;
        }

        $this->debug('Git repository found: ' . $root);

        try {
            $currentProject = $this->getCurrentProject();
        } catch (ProjectNotFoundException $e) {
            $currentProject = false;
        }
        if ($currentProject && $currentProject->id === $project->id) {
            $this->stdErr->writeln(sprintf(
                'The remote project for this repository is already set as: %s',
                $this->api()->getProjectLabel($currentProject)
            ));

            return 0;
        }

        $this->stdErr->writeln(sprintf(
            'Setting the remote project for this repository to: %s',
            $this->api()->getProjectLabel($project)
        ));

        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $localProject->mapDirectory($root, $project);

        if ($input->isInteractive()) {
            $questionHelper = $this->getService('question_helper');
            $currentBranch = $git->getCurrentBranch($root);
            $currentEnvironment = $currentBranch ? $this->api()->getEnvironment($currentBranch, $project) : false;
            if ($currentBranch !== false && $currentEnvironment && $currentEnvironment->has_code) {
                $headSha = $git->execute(['rev-parse', '--verify', 'HEAD'], $root);
                if ($currentEnvironment->head_commit === $headSha) {
                    $this->stdErr->writeln(sprintf("\nThe local branch <info>%s</info> is up to date.", $currentBranch));
                } elseif ($questionHelper->confirm("\nDo you want to pull code from the project?")) {
                    $success = $git->pull($project->getGitUrl(), $currentEnvironment->id, $root, false);

                    return $success ? 0 : 1;
                }
            }
        }

        return 0;
    }
}
