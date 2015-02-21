<?php

namespace Platformsh\Cli\Command;

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

        if (!$this->validateOptions($input, $output)) {
            return 1;
        }

        $integration = $this->getSelectedProject()->addIntegration($this->values['type'], $this->values);
        $id = $integration['id'];
        $output->writeln("Integration <info>$id</info> created for <info>{$this->values['type']}</info>");

        $output->writeln($this->formatIntegrationData($integration));

        return 0;
    }

}
