<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:get and variable:list instead
 */
class ProjectVariableGetCommand extends CommandBase
{
    protected static $defaultName = 'project:variable:get|project-variables|pvget';
    protected $hiddenInList = true;
    protected $stability = 'deprecated';

    private $table;
    private $selector;
    private $subCommandRunner;

    public function __construct(
        Table $table,
        Selector $selector,
        SubCommandRunner $subCommandRunner
    ) {
        $this->table = $table;
        $this->selector = $selector;
        $this->subCommandRunner = $subCommandRunner;
        parent::__construct();
        $this->setHiddenAliases(['project:variable:list']);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'The name of the variable')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the full variable value only (a "name" must be specified)')
            ->setDescription('View variable(s) for a project');
        $this->selector->addProjectOption($this->getDefinition());
        $this->table->configureInput($this->getDefinition());
        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use <info>variable:list</info> and <info>variable:get</info>"
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return $this->subCommandRunner->run('variable:get', [
            'name' => $input->getArgument('name'),
            '--level' => 'project',
        ]);
    }
}
