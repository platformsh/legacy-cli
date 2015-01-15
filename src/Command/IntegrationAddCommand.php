<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Integration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationAddCommand extends IntegrationCommand
{

    protected $values = array();

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('integration:add')
          ->setDescription('Add an integration to the project');
        $this->setUpOptions();
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $client = $this->getPlatformClient($this->project['endpoint']);

        if (!$this->validateOptions($input, $output)) {
            return 1;
        }

        $integration = Integration::create($this->values, 'integrations', $client);
        $id = $integration->id();
        $output->writeln("Integration <info>$id</info> created for <info>{$this->values['type']}</info>");

        /** @var Integration $integration */
        $output->writeln($integration->formatData());

        return 0;
    }

}
