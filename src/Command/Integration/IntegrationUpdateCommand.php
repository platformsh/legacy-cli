<?php
namespace Platformsh\Cli\Command\Integration;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationUpdateCommand extends IntegrationCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('integration:update')
          ->addArgument('id', InputArgument::REQUIRED, 'The ID of the integration to update')
          ->setDescription('Update an integration');
        $this->getForm()->configureInputDefinition($this->getDefinition());
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

        $values = [];
        $form = $this->getForm();
        $currentValues = $integration->getProperties();
        foreach ($form->getFields() as $key => $field) {
            $value = $field->getValueFromInput($input);
            if ($value !== null && $currentValues[$key] != $value) {
                $values[$key] = $value;
            }
        }
        if (!$values) {
            throw new \InvalidArgumentException("No values were provided to update");
        }

        $integration->update($values);
        $this->stdErr->writeln("Integration <info>$id</info> (<info>{$integration->type}</info>) updated");

        $output->writeln($this->formatIntegrationData($integration));

        return 0;
    }

}
