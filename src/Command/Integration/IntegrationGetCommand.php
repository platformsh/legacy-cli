<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Util\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationGetCommand extends IntegrationCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('integration:list')
            ->setAliases(['integrations'])
            ->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to list integrations')
            ->setDescription('View project integration(s)');
        Table::addFormatOption($this->getDefinition());
        $this->addProjectOption();
        $this->setHiddenAliases(['integration:get']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $id = $input->getArgument('id');

        if ($id) {
            $integration = $this->getSelectedProject()
                                ->getIntegration($id);
            if (!$integration) {
                $this->stdErr->writeln("Integration not found: <error>$id</error>");

                return 1;
            }
            $results = [$integration];
        } else {
            $results = $this->getSelectedProject()
                            ->getIntegrations();
            if (!$results) {
                $this->stdErr->writeln('No integrations found');

                return 1;
            }
        }

        $table = new Table($input, $output);

        $header = ['ID', 'Type', 'Details'];
        $rows = [];
        foreach ($results as $integration) {
            $data = $this->formatIntegrationData($integration);
            $rows[] = [
                $integration->id,
                $integration->type,
                $data,
            ];
        }

        $table->render($rows, $header);

        return 0;
    }
}
