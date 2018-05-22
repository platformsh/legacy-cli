<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRedeployCommand extends Command
{
    protected static $defaultName = 'environment:redeploy';

    private $activityMonitor;
    private $questionHelper;
    private $selector;

    public function __construct(ActivityMonitor $activityMonitor, QuestionHelper $questionHelper, Selector $selector)
    {
        $this->activityMonitor = $activityMonitor;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['redeploy'])
            ->setDescription('Redeploy an environment');
        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityMonitor->addWaitOptions($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environment = $this->selector->getSelection($input)->getEnvironment();

        if (!$this->questionHelper->confirm('Are you sure you want to redeploy the environment <comment>' . $environment->id . '</comment>?')) {
            return 1;
        }

        $activity = $environment->redeploy();

        if ($this->activityMonitor->shouldWait($input)) {
            $success = $this->activityMonitor->waitAndLog($activity);
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
