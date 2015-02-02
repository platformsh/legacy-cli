<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Integration;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationUpdateCommand extends IntegrationCommand
{

    protected $values = array();

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('integration:update')
          ->addArgument('id', InputArgument::REQUIRED, 'The ID of the integration to update')
          ->setDescription('Update an integration');
        $this->setUpOptions();
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $client = $this->getPlatformClient($this->project['endpoint']);

        $id = $input->getArgument('id');
        $integration = Integration::get($id, 'integrations', $client);
        if (!$integration) {
            $output->writeln("Integration not found: <error>$id</error>");
            return 1;
        }
        $this->values = $integration->getProperties();
        if (!$this->validateOptions($input, $output)) {
            return 1;
        }
        $integration->update($this->values);
        $output->writeln("Integration <info>$id</info> (<info>{$this->values['type']}</info>) updated");

        /** @var Integration $integration */
        $output->writeln($integration->formatData());

        return 0;
    }

}
