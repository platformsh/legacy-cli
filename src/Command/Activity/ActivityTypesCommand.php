<?php

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityLoader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityTypesCommand extends CommandBase
{
    protected function configure()
    {
        $this->setName('activity:types')
            ->setDescription('List activity types');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $types = ActivityLoader::getAvailableTypes();
        natcasesort($types);
        $output->writeln($types);
    }
}
