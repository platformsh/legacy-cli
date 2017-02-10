<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
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
            ->addArgument('project', InputArgument::REQUIRED, 'The project ID');
        $this->addExample('Set the remote project for this repository to "abcdef123456"', 'abcdef123456');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectId = $input->getArgument('project');
        $result = $this->parseProjectId($projectId);
        $project = $this->selectProject($result['projectId'] ?: $projectId);

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $git->ensureInstalled();
        $root = $git->getRoot(getcwd());
        if ($root === false) {
            $this->stdErr->writeln(
                'No Git repository found. Use <info>git init</info> to create a repository.'
            );

            return 1;
        }

        $this->debug('Git repository found: ' . $root);

        $currentProject = $this->getCurrentProject();
        if ($currentProject && $currentProject->id === $project->id) {
            $this->stdErr->writeln(sprintf(
                'The remote project for this repository is already set as: <info>%s</info>',
                $this->api()->getProjectLabel($currentProject)
            ));

            return 0;
        }

        $this->stdErr->writeln(sprintf(
            'Setting the remote project for this repository to: <info>%s</info>',
            $this->api()->getProjectLabel($project)
        ));

        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $localProject->mapDirectory($root, $project);

        return 0;
    }
}
