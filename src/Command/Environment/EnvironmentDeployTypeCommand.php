<?php

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\SelectorConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:deploy:type', description: 'Show or set the environment deployment type')]
class EnvironmentDeployTypeCommand extends CommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::OPTIONAL, 'The environment deployment type: automatic or manual.')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the deployment type to stdout');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addExample('Set the deployment type to "manual" (disable automatic deployments)', 'manual');
        $this->setHelp("Choose <info>automatic</info> (the default) if you want your changes to be deployed immediately as they are made."
            . "\nChoose <info>manual</info> to have changes staged until you trigger a deployment (including changes to code, variables, domains and settings).");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(
            chooseEnvFilter: SelectorConfig::filterEnvsByStatus(['active', 'paused']),
        ));

        $environment = $selection->getEnvironment();
        $settings = $environment->getSettings();
        $currentType = $settings->enable_manual_deployments ? 'manual' : 'automatic';

        $newType = $input->getArgument('type');
        if ($newType === null) {
            if ($input->getOption('pipe')) {
                $output->writeln($currentType);
                return 0;
            }
            $this->selector->ensurePrintedSelection($selection, true);
            $this->stdErr->writeln(sprintf('Deployment type: <info>%s</info>', $currentType));
            return 0;
        }

        if ($newType !== 'manual' && $newType !== 'automatic') {
            throw new InvalidArgumentException(sprintf('Invalid value "%s": the deployment type must be one of "automatic" or "manual".', $newType));
        }

        $this->selector->ensurePrintedSelection($selection, true);

        if ($newType === $currentType) {
            $this->stdErr->writeln(sprintf('The deployment type is already <info>%s</info>.', $currentType));
            return 0;
        }

        if ($newType === 'manual' && !$environment->isActive()) {
            $this->stdErr->writeln('The <comment>manual</comment> deployment type is not available as the environment is not active.');
            return 0;
        }

        $this->stdErr->writeln(sprintf(
            'Changing the deployment type from <info>%s</info> to <info>%s</info>...',
            $currentType,
            $newType
        ));

        if ($newType == 'automatic') {
            $activities = $environment->getActivities(0, null, null, Activity::STATE_STAGED);
            if (count($activities) > 0) {
                $this->stdErr->writeln('Updating this setting will immediately deploy staged changes.');
                if (!$this->questionHelper->confirm('Are you sure you want to continue?')) {
                    return 1;
                }
            }
        }

        $result = $settings->update(['enable_manual_deployments' => $newType === 'manual']);

        if ($result->getActivities() && $this->activityMonitor->shouldWait($input)) {
            $success = $this->activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
            if (!$success) {
                return 1;
            }
        }

        $this->stdErr->writeln(sprintf('The deployment type was updated successfully to: <info>%s</info>', $newType));

        return 0;
    }
}
