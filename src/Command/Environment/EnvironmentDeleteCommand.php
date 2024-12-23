<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\ProjectSshInfo;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:delete', description: 'Delete one or more environments')]
class EnvironmentDeleteCommand extends CommandBase
{
    public function __construct(
        private readonly ActivityMonitor $activityMonitor,
        private readonly Api             $api,
        private readonly Config          $config,
        private readonly ProjectSshInfo  $projectSshInfo,
        private readonly QuestionHelper  $questionHelper,
        private readonly Selector        $selector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHiddenAliases(['environment:deactivate'])
            ->addArgument('environment', InputArgument::IS_ARRAY, "The environment(s) to delete.\n" . Wildcard::HELP . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('delete-branch', null, InputOption::VALUE_NONE, 'Delete Git branch(es) for inactive environments, without confirmation')
            ->addOption('no-delete-branch', null, InputOption::VALUE_NONE, 'Do not delete any Git branch(es) (inactive environments)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Delete all environments of a type (adding to any others selected)' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('only-type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only delete environments of a specific type' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "Environment(s) not to delete.\n" . Wildcard::HELP . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('exclude-type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Environment type(s) of which not to delete' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('inactive', null, InputOption::VALUE_NONE, 'Delete all inactive environments (adding to any others selected)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Delete all environments of a status (adding to any others selected)' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('only-status', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only delete environments of a specific status' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('exclude-status', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Environment status(es) of which not to delete' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('merged', null, InputOption::VALUE_NONE, 'Delete all merged environments (adding to any others selected)')
            ->addOption('allow-delete-parent', null, InputOption::VALUE_NONE, 'Allow environments that have children to be deleted');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addExample('Delete the currently checked out environment');
        $this->addExample('Delete the environments "test" and "example-1"', 'test example-1');
        $this->addExample('Delete all inactive environments', '--inactive');
        $this->addExample('Delete all environments merged with their parent', '--merged');
        $service = $this->config->getStr('service.name');
        $this->setHelp(
            <<<EOF
                When a {$service} environment is deleted, it will become "inactive": it will
                exist only as a Git branch, containing code but no services, databases nor
                files.

                This command allows you to delete environments as well as their Git branches.
                EOF,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Select the current project, deliberately ignoring the 'environment'
        // argument and option, as those will be processed separately.
        $inputCopy = clone $input;
        $inputCopy->setArgument('environment', null);
        $inputCopy->setOption('environment', null);
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: false));

        $environments = $this->api->getEnvironments($selection->getProject());

        /**
         * A list of selected environments, keyed by ID to avoid duplication.
         *
         * @var array<string, Environment> $selectedEnvironments
         */
        $selectedEnvironments = [];
        $error = false;
        $anythingSpecified = false;

        // Add the environment(s) specified in the arguments or options.
        $specifiedEnvironmentIds = ArrayArgument::getArgument($input, 'environment');
        if ($input->getOption('environment')) {
            $specifiedEnvironmentIds = array_merge([$input->getOption('environment')], $specifiedEnvironmentIds);
        }
        if ($specifiedEnvironmentIds) {
            $anythingSpecified = true;
            $allIds = \array_map(fn(Environment $e) => $e->id, $environments);
            $specifiedEnvironmentIds = Wildcard::select($allIds, $specifiedEnvironmentIds);
            $notFound = array_diff($specifiedEnvironmentIds, array_keys($environments));
            if (!empty($notFound)) {
                // Refresh the environments list if any environment is not found.
                $environments = $this->api->getEnvironments($selection->getProject(), true);
                $notFound = array_diff($specifiedEnvironmentIds, array_keys($environments));
            }
            foreach ($notFound as $notFoundId) {
                $this->stdErr->writeln("Environment not found: <error>$notFoundId</error>");
                $error = true;
            }
            $specifiedEnvironments = array_intersect_key($environments, array_flip($specifiedEnvironmentIds));
            $this->stdErr->writeln($this->formatPlural(count($specifiedEnvironments), 'environment') . ' found by ID.');
            $this->stdErr->writeln('');
            foreach ($specifiedEnvironments as $specifiedEnvironment) {
                $selectedEnvironments[$specifiedEnvironment->id] = $specifiedEnvironment;
            }
        }

        // Gather inactive environments.
        if ($input->getOption('inactive')) {
            $anythingSpecified = true;
            if ($input->getOption('no-delete-branch')) {
                $this->stdErr->writeln('The option --no-delete-branch cannot be combined with --inactive.');

                return 1;
            }
            $inactive = array_filter(
                $environments,
                fn($environment): bool =>
                    /** @var Environment $environment */
                    $environment->status == 'inactive',
            );
            $this->stdErr->writeln($this->formatPlural(count($inactive), 'inactive environment') . ' found.');
            $this->stdErr->writeln('');
            foreach ($inactive as $inactiveEnv) {
                $selectedEnvironments[$inactiveEnv->id] = $inactiveEnv;
            }
        }

        // Gather merged environments.
        if ($input->getOption('merged')) {
            $anythingSpecified = true;
            $merged = [];
            foreach ($environments as $environment) {
                $merge_info = $environment->getProperty('merge_info', false) ?: [];
                if (isset($environment->parent, $merge_info['commits_ahead'], $merge_info['parent_ref']) && $merge_info['commits_ahead'] === 0) {
                    $selectedEnvironments[$environment->id] = $merged[$environment->id] = $environment;
                }
            }
            $this->stdErr->writeln($this->formatPlural(count($merged), 'merged environment') . ' found.');
            $this->stdErr->writeln('');
        }

        // Gather environments with the specified --type (can be multiple).
        if ($types = ArrayArgument::getOption($input, 'type')) {
            $anythingSpecified = true;
            $withTypes = [];
            foreach ($environments as $environment) {
                if (\in_array($environment->type, $types)) {
                    $selectedEnvironments[$environment->id] = $withTypes[$environment->id] = $environment;
                }
            }
            $this->stdErr->writeln($this->formatPlural(count($withTypes), 'environment') . ' found matching type(s): ' . implode(', ', $types));
            $this->stdErr->writeln('');
        }

        // Gather environments with the specified --status (can be multiple).
        if ($statuses = ArrayArgument::getOption($input, 'status')) {
            $anythingSpecified = true;
            $withStatuses = [];
            foreach ($environments as $environment) {
                if (\in_array($environment->status, $statuses)) {
                    $selectedEnvironments[$environment->id] = $withStatuses[$environment->id] = $environment;
                }
            }
            $this->stdErr->writeln($this->formatPlural(count($withStatuses), 'environment') . ' found with the status(es): ' . implode(', ', $statuses));
            $this->stdErr->writeln('');
        }

        // Add the current environment if nothing is otherwise specified.
        if (!$anythingSpecified
            && empty($selectedEnvironments)
            && ($current = $this->selector->getCurrentEnvironment($selection->getProject()))) {
            $this->stdErr->writeln('Nothing specified; selecting the current environment: ' . $this->api->getEnvironmentLabel($current));
            $this->stdErr->writeln('');
            $selectedEnvironments[$current->id] = $current;
        }

        // Exclude environment type(s) specified via --exclude-type or --only-type.
        $excludeTypes = ArrayArgument::getOption($input, 'exclude-type');
        $onlyTypes = ArrayArgument::getOption($input, 'only-type');
        $filtered = \array_filter($selectedEnvironments, function (Environment $environment) use ($excludeTypes, $onlyTypes): bool {
            if (\in_array($environment->type, $excludeTypes, true)) {
                return false;
            }
            if (!empty($onlyTypes) && !\in_array($environment->type, $onlyTypes, true)) {
                return false;
            }
            return true;
        });
        if (($numExcluded = count($selectedEnvironments) - count($filtered)) > 0) {
            $this->stdErr->writeln($this->formatPlural($numExcluded, 'environment') . ' excluded by type.');
            $this->stdErr->writeln('');
        }
        $selectedEnvironments = $filtered;

        // Exclude environment status(es) specified via --exclude-status or --only-status.
        $excludeStatuses = ArrayArgument::getOption($input, 'exclude-status');
        $onlyStatuses = ArrayArgument::getOption($input, 'only-status');
        $filtered = \array_filter($selectedEnvironments, function (Environment $environment) use ($excludeStatuses, $onlyStatuses): bool {
            if (\in_array($environment->status, $excludeStatuses, true)) {
                return false;
            }
            if (!empty($onlyStatuses) && !\in_array($environment->status, $onlyStatuses, true)) {
                return false;
            }
            return true;
        });
        if (($numExcluded = count($selectedEnvironments) - count($filtered)) > 0) {
            $this->stdErr->writeln($this->formatPlural($numExcluded, 'environment') . ' excluded by status.');
            $this->stdErr->writeln('');
        }
        $selectedEnvironments = $filtered;

        // Exclude environment ID(s) specified in --exclude.
        $excludeIds = ArrayArgument::getOption($input, 'exclude');
        if (!empty($excludeIds)) {
            $resolved = Wildcard::select(\array_keys($selectedEnvironments), $excludeIds);
            if (count($resolved)) {
                $selectedEnvironments = \array_diff_key($selectedEnvironments, \array_flip($resolved));
                $this->stdErr->writeln($this->formatPlural(count($resolved), 'environment') . ' excluded by ID.');
                $this->stdErr->writeln('');
            }
        }

        // Exclude environments which have children.
        if (!$input->getOption('allow-delete-parent')) {
            $filtered = \array_filter($selectedEnvironments, function (Environment $environment) use ($environments): bool {
                foreach ($environments as $potentialChild) {
                    if ($potentialChild->parent === $environment->id) {
                        return false;
                    }
                }
                return true;
            });
            if (($numExcluded = count($selectedEnvironments) - count($filtered)) > 0) {
                if ($numExcluded === 1) {
                    $this->stdErr->writeln('1 environment excluded as it is has child environment(s).');
                } else {
                    $this->stdErr->writeln($numExcluded . ' environments excluded as they have child environment(s).');
                }
                $this->stdErr->writeln('You can skip this check using: <comment>--allow-delete-parent</comment>');
                $this->stdErr->writeln('');
            }
            $selectedEnvironments = $filtered;
        }

        // Finally report the selected environments.
        if (count($selectedEnvironments)) {
            if (count($selectedEnvironments) === 1) {
                $this->stdErr->writeln('Selected environment: ' . $this->listEnvironments($selectedEnvironments));
            } else {
                $this->stdErr->writeln('Selected environments: ' . $this->listEnvironments($selectedEnvironments));
            }
            $this->stdErr->writeln('');
        }

        // Confirm which of the environments the user wishes to be deleted.
        ksort($selectedEnvironments, SORT_NATURAL | SORT_FLAG_CASE);
        $toDeleteBranch = [];
        $toDeactivate = [];
        $shouldWait = $this->activityMonitor->shouldWait($input);

        $byStatus = ['deleting' => [], 'dirty' => [], 'active or paused' => [], 'inactive' => []];
        foreach ($selectedEnvironments as $key => $environment) {
            if (in_array($environment->status, ['active', 'paused'])) {
                $byStatus['active or paused'][$key] = $environment;
            } else {
                $byStatus[$environment->status][$key] = $environment;
            }
        }

        $codeSourceIntegration = null;
        if ($this->projectSshInfo->hasExternalGitHost($selection->getProject())) {
            $codeSourceIntegration = $this->api->getCodeSourceIntegration($selection->getProject());
        }
        $integrationPrunesBranches = $codeSourceIntegration && $codeSourceIntegration->getProperty('prune_branches', false);

        foreach ($byStatus as $status => $environments) {
            if (count($environments) === 0) {
                continue;
            }
            $isSubSet = count($environments) !== count($selectedEnvironments);
            $isSingle = count($environments) === 1;
            switch ($status) {
                case 'dirty':
                    if ($isSingle) {
                        $this->stdErr->writeln(sprintf("The environment %s has in-progress activity, and therefore can't be deleted yet.", $this->api->getEnvironmentLabel(reset($environments), 'error')));
                    } elseif ($isSubSet) {
                        $this->stdErr->writeln("The following environments have in-progress activity, and therefore can't be deleted yet: " . $this->listEnvironments($environments, 'error'));
                    } else {
                        $this->stdErr->writeln("The environments have in-progress activity, and therefore can't be deleted yet.");
                    }
                    $this->stdErr->writeln('');
                    $error = true;
                    break;
                case 'deleting':
                    if ($isSingle) {
                        $this->stdErr->writeln(sprintf('The environment %s is already being deleted.', $this->api->getEnvironmentLabel(reset($environments), 'error')));
                    } elseif ($isSubSet) {
                        $this->stdErr->writeln('The following environments are already being deleted: ' . $this->listEnvironments($environments, 'error'));
                    } else {
                        $this->stdErr->writeln('The environments are already being deleted.');
                    }
                    $this->stdErr->writeln('');
                    break;
                case 'active or paused':
                    $confirmText = 'Are you sure you want to delete them?';
                    $deleteConfirmText = 'Delete the inactive environments (Git branches) too?';
                    if ($isSingle) {
                        $this->stdErr->writeln(sprintf('The environment %s is currently active.', $this->api->getEnvironmentLabel(reset($environments), 'comment')));
                        $this->stdErr->writeln('Deleting it <options=bold>will delete all associated data</>.');
                        $confirmText = 'Are you sure you want to delete this environment?';
                        $deleteConfirmText = 'Delete the inactive environment (Git branch) too?';
                    } elseif ($isSubSet) {
                        $this->stdErr->writeln('The following environments are currently active: ' . $this->listEnvironments($environments, 'comment'));
                        $this->stdErr->writeln('Deleting them <options=bold>will delete all associated data</>.');
                    } else {
                        $this->stdErr->writeln('The environments are currently active. Deleting them <options=bold>will delete all associated data</>.');
                    }
                    if ($this->questionHelper->confirm($confirmText)) {
                        $toDeactivate += $environments;
                        if ($input->getOption('delete-branch')) {
                            if (!$shouldWait) {
                                if ($isSingle) {
                                    $this->stdErr->writeln('The Git branch cannot be deleted until the environment has been deactivated.');
                                } else {
                                    $this->stdErr->writeln('The Git branch cannot be deleted until each environment has been deactivated.');
                                }
                                $error = true;
                            } else {
                                $toDeleteBranch += $environments;
                            }
                        } elseif ($shouldWait && $input->isInteractive() && !$input->getOption('no-delete-branch') && !$integrationPrunesBranches && $this->questionHelper->confirm($deleteConfirmText)) {
                            $toDeleteBranch += $environments;
                        }
                    } else {
                        $error = true;
                    }
                    $this->stdErr->writeln('');
                    break;
                case 'inactive':
                    if ($input->getOption('no-delete-branch')) {
                        if ($isSingle) {
                            $this->stdErr->writeln(sprintf('The environment %s is inactive and <comment>--no-delete-branch</comment> was specified, so it will not be deleted.', $this->api->getEnvironmentLabel(reset($environments), 'comment')));
                        } elseif ($isSubSet) {
                            $this->stdErr->writeln('The following environment(s) are inactive and <comment>--no-delete-branch</comment> was specified, so they will not be deleted: ' . $this->listEnvironments($environments, 'comment'));
                        } else {
                            $this->stdErr->writeln('The environment(s) are inactive and <comment>--no-delete-branch</comment> was specified, so they will not be deleted.');
                        }
                        $this->stdErr->writeln('');
                        break;
                    }
                    if ($codeSourceIntegration && $integrationPrunesBranches) {
                        $this->stdErr->writeln(sprintf("The project's branches are managed externally through its <comment>%s</comment> integration, so inactive environments cannot be deleted directly.", $codeSourceIntegration->type));
                        $this->stdErr->writeln('');
                        $error = true;
                        break;
                    }
                    if ($isSingle) {
                        $message = sprintf('Are you sure you want to delete the inactive environment %s?', $this->api->getEnvironmentLabel(reset($environments), 'comment'));
                    } elseif ($isSubSet) {
                        $message = 'The following environment(s) are inactive: ' . $this->listEnvironments($environments, 'comment')
                            . "\nAre you sure you want to delete them?";
                    } else {
                        $message = sprintf('Are you sure you want to delete <comment>%d</comment> inactive environment(s)?', count($environments));
                    }
                    if ($input->getOption('delete-branch') || $this->questionHelper->confirm($message)) {
                        $toDeleteBranch += $environments;
                    } else {
                        $error = true;
                    }
                    $this->stdErr->writeln('');
                    break;
                default:
                    if ($isSubSet) {
                        $this->stdErr->writeln("The following environment(s) have the unrecognised status <error>$status</error>: " . $this->listEnvironments($environments, 'error'));
                    } else {
                        $this->stdErr->writeln("The environment(s) have the unrecognised status: <error>$status</error>");
                    }
                    $this->stdErr->writeln('');
                    $error = true;
                    break;
            }
        }

        if (empty($toDeleteBranch) && empty($toDeactivate)) {
            $this->stdErr->writeln('No environments to delete.');
            if (!$anythingSpecified) {
                $this->stdErr->writeln(\sprintf('For help, run: <info>%s help environment:delete</info>', $this->config->getStr('application.executable')));
            }

            return $error ? 1 : 0;
        }

        $success = $this->deleteMultiple($toDeactivate, $toDeleteBranch, $selection->getProject(), $input) && !$error;

        return $success ? 0 : 1;
    }

    /**
     * @param Environment[] $environments
     * @param string $tag
     *
     * @return string
     */
    private function listEnvironments(array $environments, string $tag = 'info'): string
    {
        $uniqueIds = \array_unique(\array_map(fn(Environment $e) => $e->id, $environments));
        natcasesort($uniqueIds);
        return "<$tag>" . implode("</$tag>, <$tag>", $uniqueIds) . "</$tag>";
    }

    /**
     * @param Environment[] $toDeactivate
     * @param Environment[] $toDeleteBranch
     */
    protected function deleteMultiple(array $toDeactivate, array $toDeleteBranch, Project $project, InputInterface $input): bool
    {
        $error = false;
        $deactivateActivities = [];
        $deactivated = 0;
        foreach ($toDeactivate as $environmentId => $environment) {
            try {
                $this->stdErr->writeln("Deleting environment <info>$environmentId</info>");
                $deactivateActivities = array_merge($deactivateActivities, $environment->runOperation('deactivate')->getActivities());
                $deactivated++;
            } catch (\Exception $e) {
                $this->stdErr->writeln($e->getMessage());
            }
        }

        if ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            if (!$activityMonitor->waitMultiple($deactivateActivities, $project)) {
                $error = true;
            }
        }

        $deleted = 0;
        if (count($toDeactivate) > 0 && count($toDeleteBranch) > 0) {
            $this->stdErr->writeln('');
        }
        foreach ($toDeleteBranch as $environmentId => $environment) {
            try {
                if ($environment->status !== 'inactive') {
                    $environment->refresh();
                    if ($environment->status !== 'inactive') {
                        $this->stdErr->writeln("Cannot delete Git branch for <error>$environmentId</error>: the environment is not (yet) inactive.");
                        continue;
                    }
                }
                $this->stdErr->writeln("Deleting inactive environment <info>$environmentId</info>");
                $environment->delete();
                $deleted++;
            } catch (\Exception $e) {
                $this->stdErr->writeln($e->getMessage());
            }
        }

        if ($deleted > 0) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln("Run <info>git fetch --prune</info> to remove deleted branches from your local cache.");
        }

        if ($deleted < count($toDeleteBranch) || $deactivated < count($toDeactivate)) {
            $error = true;
        }

        if (($deleted || $deactivated || $error) && isset($environment)) {
            $this->api->clearEnvironmentsCache($environment->project);
        }

        return !$error;
    }

    /**
     * Formats a string with a singular or plural count.
     */
    private function formatPlural(int $count, string $singular, ?string $plural = null): string
    {
        if ($count === 1) {
            $name = $singular;
        } else {
            $name = $plural === null ? $singular . 's' : $plural;
        }
        return sprintf('%d %s', $count, $name);
    }
}
