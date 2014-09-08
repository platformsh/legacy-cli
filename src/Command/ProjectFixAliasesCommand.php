<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectFixAliasesCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('project:fix-aliases')
            ->setAliases(array('fix-aliases'))
            ->setDescription('Forces the CLI to recreate the project\'s site (Drush) aliases, if any.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadConfig();

        $projects = $this->config['projects'];

        foreach ($projects as $projectId => $project) {
            $environments = $this->config['environments'][$projectId];
            $this->createDrushAliases($project, $environments);
        }

        $output->writeln("Project aliases recreated.");
    }
}
