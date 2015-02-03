<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Integration;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationDeleteCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('integration:delete')
          ->addArgument('id', InputArgument::REQUIRED, 'The integration ID')
          ->setDescription('Delete an integration from a project');
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $id = $input->getArgument('id');

        $client = $this->getPlatformClient($this->project['endpoint']);
        $integration = Integration::get($id, 'integrations', $client);

        if (!$integration) {
            $output->writeln("Integration not found: <error>$id</error>");
            return 1;
        }

        if (!$integration->operationAvailable('delete')) {
            $output->writeln("The integration <error>$id</error> cannot be deleted");
            return 1;
        }

        $type = $integration->getProperty('type');
        $confirmText = "Delete the integration <info>$id</info> (type: $type)?";
        if (!$this->getHelper('question')->confirm($confirmText, $input, $output)) {
            return 1;
        }

        $integration->delete();

        $output->writeln("Deleted integration <info>$id</info>");
        return 0;
    }

}
