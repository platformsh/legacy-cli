<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Util\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationGetCommand extends IntegrationCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('integration:get')
            ->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addOption('property', 'P', InputOption::VALUE_OPTIONAL, 'The integration property to view')
            ->setDescription('View details of an integration');
        Table::addFormatOption($this->getDefinition());
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();

        $id = $input->getArgument('id');
        if (!$id && !$input->isInteractive()) {
            $this->stdErr->writeln('An integration ID is required.');

            return 1;
        }
        elseif (!$id) {
            $integrations = $project->getIntegrations();
            /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            $choices = [];
            foreach ($integrations as $integration) {
                $choices[$integration->id] = sprintf('%s (%s)', $integration->id, $integration->type);
            }
            $id = $questionHelper->choose($choices, 'Enter a number to choose an integration:');
        }

        $integration = $this->getSelectedProject()
                            ->getIntegration($id);
        if (!$integration) {
            $this->stdErr->writeln("Integration not found: <error>$id</error>");

            return 1;
        }

        if ($property = $input->getOption('property')) {
            if ($property === 'hook_url' && $integration->hasLink('#hook')) {
                $value = $integration->getLink('#hook');
            }
            else {
                $value = $this->api()->getNestedProperty($integration, $property);
            }

            $output->writeln($this->propertyFormatter->format($value, $property));

            return 0;
        }

        $this->displayIntegration($integration, $input, $output);

        return 0;
    }
}
