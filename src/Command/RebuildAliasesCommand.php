<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RebuildAliasesCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('rebuild-aliases')
            ->setDescription('Forces a rebuild of site aliases.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadConfig();
        
        $projects = $this->config['projects'];
        
        foreach ($projects as $projectId => $project) {
            $environments = $this->config['environments'][$projectId];
            $this->createDrushAliases($project, $environments);
        }

        $output->writeln("Site aliases rebuilt.");
    }
}
