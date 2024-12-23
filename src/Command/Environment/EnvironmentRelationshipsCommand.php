<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:relationships', description: 'Show an environment\'s relationships', aliases: ['relationships', 'rel'])]
class EnvironmentRelationshipsCommand extends CommandBase
{
    public function __construct(private readonly PropertyFormatter $propertyFormatter, private readonly Relationships $relationships, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The relationship property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the relationships');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Ssh::configureInput($this->getDefinition());
        $this->addExample("View all the current environment's relationships");
        $this->addExample("View the 'main' environment's relationships", 'main');
        $this->addExample("View the 'main' environment's database port", 'main --property database.0.port');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(allowLocalHost: $this->relationships->hasLocalEnvVar(), chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));
        $host = $this->selector->getHostFromSelection($input, $selection);

        $relationships = $this->relationships->getRelationships($host, $input->getOption('refresh'));

        foreach ($relationships as $name => $relationship) {
            foreach ($relationship as $index => $instance) {
                if (!isset($instance['url'])) {
                    $relationships[$name][$index]['url'] = $this->relationships->buildUrl($instance);
                }
            }
        }
        $this->propertyFormatter->displayData($output, $relationships, $input->getOption('property'));

        return 0;
    }
}
