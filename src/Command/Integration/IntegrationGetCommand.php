<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integration:get', description: 'View details of an integration')]
class IntegrationGetCommand extends IntegrationCommandBase
{
    public function __construct(private readonly Api $api, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addOption('property', 'P', InputOption::VALUE_OPTIONAL, 'The integration property to view');
        Table::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $project = $selection->getProject();

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

            $formatter = $this->propertyFormatter;
            $output->writeln($formatter->format($value, $property));

            return 0;
        }

        $this->displayIntegration($integration);

        return 0;
    }
}
