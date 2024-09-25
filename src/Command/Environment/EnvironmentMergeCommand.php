<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentMergeCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:merge')
            ->setAliases(['merge'])
            ->setDescription('Merge an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment to merge');
        $this->addResourcesInitOption(['child', 'default', 'minimum', 'manual']);
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
        $this->addExample('Merge the environment "sprint-2" into its parent', 'sprint-2');
        $this->setHelp(
            'This command will initiate a Git merge of the specified environment into its parent environment.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment->id;

        if (!$selectedEnvironment->operationAvailable('merge', true)) {
            $this->stdErr->writeln(sprintf(
                "Operation not available: The environment <error>%s</error> can't be merged.",
                $environmentId
            ));

            if ($selectedEnvironment->getProperty('has_remote', false) === true
                && ($integration = $this->api()->getCodeSourceIntegration($this->getSelectedProject()))
                && $integration->getProperty('fetch_branches', false) === true) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf("The environment's code is managed externally through the project's <info>%s</info> integration.", $integration->type));
                if ($this->config()->isCommandEnabled('integration:get')) {
                    $this->stdErr->writeln(sprintf('To view the integration, run: <info>%s integration:get %s</info>', $this->config()->get('application.executable'), OsUtil::escapeShellArg($integration->id)));
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
        $resourcesInit = $this->validateResourcesInitInput($input, $this->getSelectedProject());
        if ($resourcesInit === false) {
            return 1;
        }

        $parentId = $selectedEnvironment->parent;

        $confirmText = sprintf(
            'Are you sure you want to merge <info>%s</info> into its parent, <info>%s</info>?',
            $environmentId,
            $parentId
        );
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm($confirmText)) {
            return 1;
        }

        $this->stdErr->writeln(sprintf(
            'Merging <info>%s</info> into <info>%s</info>',
            $environmentId,
            $parentId
        ));

        $this->api()->clearEnvironmentsCache($selectedEnvironment->project);

        $params = [];
        if ($resourcesInit !== null) {
            $params['resources']['init'] = $resourcesInit;
        }

        $result = $selectedEnvironment->runOperation('merge', 'POST', $params);
        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
