<?php
namespace Platformsh\Cli\Command\Integration;

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
        $this->addExample(
          'Add an integration with a GitHub repository',
          '--type github --repository myuser/example-repo --token UFpYS1MzQktjNw --fetch-branches 0'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        if (!$this->validateOptions($input)) {
            return 1;
        }

        $integration = $this->getSelectedProject()
                            ->addIntegration($this->values['type'], $this->values);
        $id = $integration['id'];
        $this->stdErr->writeln("Integration <info>$id</info> created for <info>{$this->values['type']}</info>");

        $output->writeln($this->formatIntegrationData($integration));

        return 0;
    }

}
