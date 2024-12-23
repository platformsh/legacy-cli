<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use "variable:update --enabled false" instead
 */
#[AsCommand(name: 'variable:disable', description: 'Disable an enabled environment-level variable')]
class VariableDisableCommand extends CommandBase
{
    protected bool $hiddenInList = true;
    protected string $stability = 'deprecated';

    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Selector $selector, private readonly SubCommandRunner $subCommandRunner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the variable');
        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use: <info>variable:update --enabled false [variable]</info>",
        );
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        return $this->subCommandRunner->run('variable:update', [
            'name' => $input->getArgument('name'),
            '--enabled' => 'false',
            '--project' => $selection->getProject()->id,
            '--environment' => $selection->getEnvironment()->id,
        ] + array_filter([
            '--wait' => $input->getOption('wait'),
            '--no-wait' => $input->getOption('no-wait'),
        ]));
    }
}
