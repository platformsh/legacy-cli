<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Integration;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationGetCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('integration:get')
            ->setAliases(array('integrations'))
            ->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to list integrations')
            ->setDescription('View project integration(s)');
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $client = $this->getPlatformClient($this->project['endpoint']);

        $id = $input->getArgument('id');

        if ($id) {
            $integration = Integration::get($id, 'integrations', $client);
            if (!$integration) {
                $output->writeln("Integration not found: <error>$id</error>");
                return 1;
            }
            $results = array($integration);
        }
        else {
            $prototype = new Integration(array(), $client);
            /** @var Integration[] $results */
            $results = Integration::getCollection('integrations', array(), $client, $prototype);
            if (!$results) {
                $output->writeln('No integrations found');
                return 1;
            }
        }

        $table = $this->buildTable($results, $output);
        $table->render();

        return 0;
    }

    /**
     * @param Integration[] $integrations
     * @param OutputInterface $output
     *
     * @return Table
     */
    protected function buildTable(array $integrations, OutputInterface $output)
    {
        $table = new Table($output);
        $table->setHeaders(array("ID", "Type", "Details"));
        foreach ($integrations as $integration) {
            $data = $integration->formatData();
            $table->addRow(array(
                $integration->id(),
                $integration->getProperty('type'),
                $data,
              ));
        }
        return $table;
    }

}
