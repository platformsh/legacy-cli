<?php

namespace Platformsh\Cli\Command\Fleet;


use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class FleetInitFleetCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('fleet:init')
            ->setDescription('Initialise this project as a fleet base')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the fleet');
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $this->debug('Loading fleet configuration');
        /* @var $fleetConfig \Platformsh\Cli\Service\Fleets */
        $fleetConfig = $this->getService('fleets');

        if($fleetConfig->addFleet($input->getArgument('name'))) {
            $this->stdErr->writeln('Added new fleet: ' . $input->getArgument('name'));
        }
        else {
            $this->stdErr->writeln('The fleet ' . $input->getArgument('name') . ' already exists.');
        };

    }


}
