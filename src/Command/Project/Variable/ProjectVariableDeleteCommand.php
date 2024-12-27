<?php
namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:delete instead
 */
#[AsCommand(name: 'project:variable:delete', description: 'Delete a variable from a project')]
class ProjectVariableDeleteCommand extends CommandBase
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
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name');
        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use: <info>variable:delete --level project [variable]</info>"
        );
        $this->selector->addProjectOption($this->getDefinition());
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        return $this->subCommandRunner->run('variable:delete', [
                'name' => $input->getArgument('name'),
                '--level' => 'project',
                '--project' => $selection->getProject()->id,
            ] + array_filter([
                '--wait' => $input->getOption('wait'),
                '--no-wait' => $input->getOption('no-wait'),
            ]));
    }
}
