<?php
namespace Platformsh\Cli\Command\Environment;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\StringUtil;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSynchronizeCommand extends CommandBase implements CompletionAwareInterface
{

    protected function configure()
    {
        $this
            ->setName('environment:synchronize')
            ->setAliases(['sync']);
        if ($this->config()->get('api.sizing')) {
            $this->setDescription("Synchronize an environment's code, data and/or resources from its parent");
            $this->addArgument('synchronize', InputArgument::IS_ARRAY, 'List what to synchronize: "code", "data", and/or "resources".');
        } else {
            $this->setDescription("Synchronize an environment's code and/or data from its parent");
            $this->addArgument('synchronize', InputArgument::IS_ARRAY, 'What to synchronize: "code", "data" or both');
        }
        $this->addOption('rebase', null, InputOption::VALUE_NONE, 'Synchronize code by rebasing instead of merging');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();

        $this->addExample('Synchronize data from the parent environment', 'data');
        $this->addExample('Synchronize code and data from the parent environment', 'code data');

        $help = <<<EOT
This command synchronizes to a child environment from its parent environment.

Synchronizing "code" means there will be a Git merge from the parent to the
child.

Synchronizing "data" means that all files in all services (including
static files, databases, logs, search indices, etc.) will be copied from the
parent to the child.
EOT;
        if ($this->config()->get('api.sizing')) {
            $help .= "\n\n" . <<<EOT
Synchronizing "resources" means that the parent environment's resource sizes
will be used for all corresponding apps and services in the child environment.
EOT;
            $this->addExample('Synchronize code, data and resources from the parent environment', 'code data resources');
        }

        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->chooseEnvFilter = $this->filterEnvsMaybeActive();
        $this->validateInput($input);

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment->id;
        $parentId = $selectedEnvironment->parent;

        if (!$selectedEnvironment->operationAvailable('synchronize', true)) {
            $this->stdErr->writeln(
                "Operation not available: The environment <error>$environmentId</error> can't be synchronized."
            );

            if ($selectedEnvironment->parent === null) {
                $this->stdErr->writeln('The environment does not have a parent.');
            } elseif ($selectedEnvironment->is_dirty) {
                $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
            } elseif (!$selectedEnvironment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            } else {
                $parentEnvironment = $this->api()->getEnvironment($parentId, $this->getSelectedProject(), false);
                if ($parentEnvironment && !$parentEnvironment->isActive()) {
                    $this->stdErr->writeln(sprintf('The parent environment <error>%s</error> is not active.', $parentId));
                }
            }

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $rebase = (bool) $input->getOption('rebase');

        $integrationManagingCode = null;
        if ($selectedEnvironment->getProperty('has_remote', false)) {
            $integration = $this->api()->getCodeSourceIntegration($this->getSelectedProject());
            if ($integration && $integration->getProperty('fetch_branches') === true) {
                $integrationManagingCode = $integration;
            }
        }

        if ($synchronize = $input->getArgument('synchronize')) {
            $validOptions = $this->config()->get('api.sizing') ? ['code', 'data', 'resources'] : ['code', 'data', 'both'];
            $toSync = [];
            foreach ($synchronize as $item) {
                if (!in_array($item, $validOptions)) {
                    $this->stdErr->writeln(sprintf('Invalid value: <error>%s</error> (it must be one of: %s).', $item, StringUtil::formatItemList($validOptions, '<comment>', '</comment>')));
                    return 1;
                }
                if ($item === 'both') {
                    array_push($toSync, 'code', 'data');
                } else {
                    $toSync[] = $item;
                }
            }
            $toSync = array_unique($toSync);

            if (in_array('code', $toSync) && $integrationManagingCode) {
                $this->stdErr->writeln(sprintf("Code cannot be synchronized as it is managed by the project's <error>%s</error> integration.", $integrationManagingCode->type));
                if ($this->config()->isCommandEnabled('integration:get')) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln(sprintf('To view the integration, run: <info>%s integration:get %s</info>', $this->config()->get('application.executable'), OsUtil::escapeShellArg($integrationManagingCode->id)));
                }
                return 1;
            }

            if (in_array('resources', $toSync) && !$this->api()->supportsSizingApi($this->getSelectedProject())) {
                $this->stdErr->writeln('Resources cannot be synchronized as the project does not support flexible resources.');
                return 1;
            }

            if ($rebase && !in_array('code', $toSync)) {
                $this->stdErr->writeln('<comment>Note:</comment> you specified the <comment>--rebase</comment> option, but this only applies to synchronizing code, which you have not selected.');
                $this->stdErr->writeln('');
            }

            $confirmText = sprintf(
                'Are you sure you want to synchronize %s from <info>%s</info> to <info>%s</info>?',
                StringUtil::formatItemList($toSync, '<options=underscore>', '</>', ' and '),
                $parentId,
                $environmentId
            );
            if (!$questionHelper->confirm($confirmText)) {
                return 1;
            }
            $this->stdErr->writeln('');
        } else {
            $toSync = [];

            if (!$integrationManagingCode) {
                $syncCode = $questionHelper->confirm(
                    "Do you want to synchronize <options=underscore>code</> from <info>$parentId</info> to <info>$environmentId</info>?",
                    false
                );

                if ($syncCode) {
                    $toSync[] = 'code';
                    if (!$rebase) {
                        $rebase = $questionHelper->confirm(
                            "Do you want to synchronize code by rebasing instead of merging?",
                            false
                        );
                    }
                } elseif ($rebase) {
                    $this->stdErr->writeln('<comment>Note:</comment> you specified the <comment>--rebase</comment> option, but this only applies to synchronizing code.');
                }

                $this->stdErr->writeln('');
            }

            if ($questionHelper->confirm(
                "Do you want to synchronize <options=underscore>data</> from <info>$parentId</info> to <info>$environmentId</info>?",
                false
            )) {
                $toSync[] = 'data';
            }

            $this->stdErr->writeln('');

            if ($this->config()->get('api.sizing') && $this->api()->supportsSizingApi($this->getSelectedProject())) {
                if ($questionHelper->confirm(
                    "Do you want to synchronize <options=underscore>resources</> from <info>$parentId</info> to <info>$environmentId</info>?",
                    false
                )) {
                    $toSync[] = 'resources';
                }

                $this->stdErr->writeln('');
            }
        }
        if (empty($toSync)) {
            $this->stdErr->writeln('You did not select anything to synchronize.');

            return 1;
        }

        $this->stdErr->writeln("Synchronizing environment <info>$environmentId</info>");

        $params = [
            'synchronize_code' => in_array('code', $toSync),
            'synchronize_data' => in_array('data', $toSync),
            'rebase' => $rebase,
        ];
        if (in_array('resources', $toSync)) {
            $params['synchronize_resources'] = true;
        }

        try {
            $result = $selectedEnvironment->runOperation('synchronize', 'POST', $params);
        } catch (BadResponseException $e) {
            // Translate validation error messages.
            if (($response = $e->getResponse()) && $response->getStatusCode() === 400 && ($body = $response->getBody())) {
                $data = \json_decode((string) $body, true);
                if (\is_array($data) && !empty($data['detail']['error'])) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln("<error>Error:</error>: " . $data['detail']['error']);
                    return 1;
                }
            }
            throw $e;
        }
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

    /**
     * {@inheritdoc}
     */
    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        if ($argumentName === 'synchronize') {
            return $this->config()->get('api.sizing') ? ['code', 'data', 'resources'] : ['code', 'data', 'both'];
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function completeOptionValues($optionName, CompletionContext $context)
    {
        return [];
    }
}
