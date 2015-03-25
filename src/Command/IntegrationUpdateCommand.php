<?php

namespace Platformsh\Cli\Command;

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

        $id = $input->getArgument('id');
        $integration = $this->getSelectedProject()
                            ->getIntegration($id);
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

        $output->writeln($this->formatIntegrationData($integration));

        return 0;
    }

}
