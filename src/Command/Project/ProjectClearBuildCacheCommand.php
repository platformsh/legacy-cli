<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectClearBuildCacheCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('project:clear-build-cache')
            ->setDescription("Clear a project's build cache");
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();
        $project->clearBuildCache();
        $this->stdErr->writeln('The build cache has been cleared on the project: ' . $this->api()->getProjectLabel($project));

        return 0;
    }
}
