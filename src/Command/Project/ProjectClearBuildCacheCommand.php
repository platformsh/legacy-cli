<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project:clear-build-cache', description: "Clear a project's build cache")]
class ProjectClearBuildCacheCommand extends CommandBase
{
    protected function configure()
    {
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();
        $project->clearBuildCache();
        $this->stdErr->writeln('The build cache has been cleared on the project: ' . $this->api()->getProjectLabel($project));

        return 0;
    }
}
