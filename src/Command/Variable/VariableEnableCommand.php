<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use "variable:update --enabled true" instead
 */
class VariableEnableCommand extends CommandBase
{
    protected static $defaultName = 'variable:enable';
    protected static $defaultDescription = 'Enable a disabled environment-level variable';

    protected $hiddenInList = true;
    protected $stability = 'deprecated';

    private $activityService;
    private $selector;
    private $subCommandRunner;

    public function __construct(
        ActivityService $activityService,
        Selector $selector,
        SubCommandRunner $subCommandRunner
    ) {
        $this->activityService = $activityService;
        $this->selector = $selector;
        $this->subCommandRunner = $subCommandRunner;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The name of the variable');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);

        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use: <info>variable:update --enabled false [variable]</info>"
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return $this->subCommandRunner->run('variable:update', [
            'name' => $input->getArgument('name'),
            '--enabled' => 'true',
        ]);
    }
}
