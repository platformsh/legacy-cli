<?php
namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Selector\Selector;
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
    public function __construct(private readonly Selector $selector)
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name');
        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use: <info>variable:delete --level project [variable]</info>"
        );
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        return $this->runOtherCommand('variable:delete', [
                'name' => $input->getArgument('name'),
                '--level' => 'project',
                '--project' => $selection->getProject()->id,
            ] + array_filter([
                '--wait' => $input->getOption('wait'),
                '--no-wait' => $input->getOption('no-wait'),
            ]));
    }
}
