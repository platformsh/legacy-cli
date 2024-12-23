<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Service\ResourcesUtil;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:merge', description: 'Merge an environment', aliases: ['merge'])]
class EnvironmentMergeCommand extends CommandBase
{
    /** @var string[] */
    private array $validResourcesInitOptions = ['child', 'default', 'minimum', 'manual'];

    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Config $config, private readonly QuestionHelper $questionHelper, private readonly ResourcesUtil $resourcesUtil, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment to merge');
        $this->resourcesUtil->addOption($this->getDefinition(), $this->validResourcesInitOptions);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addExample('Merge the environment "sprint-2" into its parent', 'sprint-2');
        $this->setHelp(
            'This command will initiate a Git merge of the specified environment into its parent environment.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $selectedEnvironment = $selection->getEnvironment();
        $environmentId = $selectedEnvironment->id;

        if (!$selectedEnvironment->operationAvailable('merge', true)) {
            $this->stdErr->writeln(sprintf(
                "Operation not available: The environment <error>%s</error> can't be merged.",
                $environmentId,
            ));

            if ($selectedEnvironment->getProperty('has_remote', false) === true
                && ($integration = $this->api->getCodeSourceIntegration($selection->getProject()))
                && $integration->getProperty('fetch_branches', false) === true) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf("The project's code is managed externally through its <info>%s</info> integration.", $integration->type));
                if ($this->config->isCommandEnabled('integration:get')) {
                    $this->stdErr->writeln(sprintf('To view the integration, run: <info>%s integration:get %s</info>', $this->config->getStr('application.executable'), OsUtil::escapeShellArg($integration->id)));
                }
            } elseif ($selectedEnvironment->parent === null) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('The environment does not have a parent.');
            } elseif ($selectedEnvironment->is_dirty) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
            }

            return 1;
        }

        // Validate the --resources-init option.
        $resourcesInit = $this->resourcesUtil->validateInput($input, $selection->getProject(), $this->validResourcesInitOptions);
        if ($resourcesInit === false) {
            return 1;
        }

        $parentId = $selectedEnvironment->parent;

        $confirmText = sprintf(
            'Are you sure you want to merge <info>%s</info> into its parent, <info>%s</info>?',
            $environmentId,
            $parentId,
        );
        if (!$this->questionHelper->confirm($confirmText)) {
            return 1;
        }

        $this->stdErr->writeln(sprintf(
            'Merging <info>%s</info> into <info>%s</info>',
            $environmentId,
            $parentId,
        ));

        $this->api->clearEnvironmentsCache($selectedEnvironment->project);

        $params = [];
        if ($resourcesInit !== null) {
            $params['resources']['init'] = $resourcesInit;
        }

        $result = $selectedEnvironment->runOperation('merge', 'POST', $params);
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
