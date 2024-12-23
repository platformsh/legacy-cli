<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project:clear-build-cache', description: "Clear a project's build cache")]
class ProjectClearBuildCacheCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();
        $project->clearBuildCache();
        $this->stdErr->writeln('The build cache has been cleared on the project: ' . $this->api->getProjectLabel($project));

        return 0;
    }
}
