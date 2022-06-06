<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationGetCommand extends IntegrationCommandBase
{
    protected static $defaultName = 'integration:get';
    protected static $defaultDescription = 'View details of an integration';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addOption('property', 'P', InputOption::VALUE_OPTIONAL, 'The integration property to view');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->table->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $integration = $this->selectIntegration($project, $input->getArgument('id'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }

        if ($property = $input->getOption('property')) {
            if ($property === 'hook_url' && $integration->hasLink('#hook')) {
                $value = $integration->getLink('#hook');
            } else {
                $value = $this->api->getNestedProperty($integration, $property);
            }

            $output->writeln($this->formatter->format($value, $property));

            return 0;
        }

        $this->displayIntegration($integration);

        return 0;
    }
}
