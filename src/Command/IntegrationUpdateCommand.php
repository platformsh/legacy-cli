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
        $this->addExample('Switch on the "fetch branches" option for a specific integration', 'ZXhhbXBsZSB --fetch-branches 1');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $id = $input->getArgument('id');
        $integration = $this->getSelectedProject()
                            ->getIntegration($id);
        if (!$integration) {
            $this->stdErr->writeln("Integration not found: <error>$id</error>");

            return 1;
        }
        $this->values = $integration->getProperties();
        if (!$this->validateOptions($input)) {
            return 1;
        }
        $integration->update($this->values);
        $this->stdErr->writeln("Integration <info>$id</info> (<info>{$this->values['type']}</info>) updated");

        $output->writeln($this->formatIntegrationData($integration));

        return 0;
    }

}
