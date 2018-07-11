<?php

namespace Platformsh\Cli\Command\Fleet;


use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Platformsh\Cli\Console\AdaptiveTableCell;

class FleetListFleets extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('fleet:list')
            ->setDescription('List all fleets');
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $this->debug('Loading fleet configuration');
        /* @var $fleetConfig \Platformsh\Cli\Service\Fleets */
        $fleetConfig = $this->getService('fleets');
        $fleets = $fleetConfig->getFleetConfiguration();

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $machineReadable = $table->formatIsMachineReadable();

        // Display a message if no projects are found.
        if (empty($fleets)) {
            $this->stdErr->writeln(
                'You do not have any ' . $this->config()->get('service.name') . ' projects yet.'
            );

            return 0;
        }

        $rows = [];
        foreach ($fleets['fleets'] as $fleetName => $fleetSettings) {

            $count = count($fleetSettings['projects']);

            $rows[] = [
                new AdaptiveTableCell($fleetName, ['wrap' => false]),
                new AdaptiveTableCell($count)
            ];
        }

        $header = ['ID', 'No. of Projects'];

        // Display a simple table (and no messages) if the --format is
        // machine-readable (e.g. csv or tsv).
        if ($machineReadable) {
            $table->render($rows, $header);

            return 0;
        }

        // Display the projects.
        if (empty($filters)) {
            $this->stdErr->writeln('Fleets attached to this project: ');
        }

        $table->render($rows, $header);

    }
}
