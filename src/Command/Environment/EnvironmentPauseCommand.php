<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:pause', description: 'Pause an environment')]
class EnvironmentPauseCommand extends CommandBase
{
    public const PAUSE_HELP = <<<EOF
        Pausing an environment helps to reduce resource consumption and carbon emissions.

        The environment will be unavailable until it is resumed. No data will be lost.
        EOF;
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->setHelp(self::PAUSE_HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));
        $environment = $selection->getEnvironment();

        if ($environment->status === 'paused') {
            $this->stdErr->writeln(sprintf(
                'The environment %s is already paused.',
                $this->api->getEnvironmentLabel($environment),
            ));
            return 0;
        }

        if (!$environment->operationAvailable('pause', true)) {
            $this->stdErr->writeln(sprintf(
                "Operation not available: The environment %s can't be paused.",
                $this->api->getEnvironmentLabel($environment, 'error'),
            ));

            if (!$environment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            }

            return 1;
        }
        $text = self::PAUSE_HELP . "\n\n" . sprintf('Are you sure you want to pause the environment <comment>%s</comment>?', $environment->id);
        if (!$this->questionHelper->confirm($text)) {
            return 1;
        }

        $result = $environment->runOperation('pause');
        $this->api->clearEnvironmentsCache($environment->project);

        if ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
