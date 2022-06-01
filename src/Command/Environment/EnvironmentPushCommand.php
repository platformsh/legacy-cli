<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ProcessFailedException;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\SshDiagnostics;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentPushCommand extends CommandBase
{
    protected static $defaultName = 'environment:push';

    private $api;
    private $activityService;
    private $config;
    private $git;
    private $localProject;
    private $questionHelper;
    private $relationships;
    private $selector;
    private $ssh;
    private $sshDiagnostics;

    public function __construct(
        Api $api,
        ActivityService $activityService,
        Config $config,
        Git $git,
        LocalProject $localProject,
        QuestionHelper $questionHelper,
        Relationships $relationships,
        Selector $selector,
        Ssh $ssh,
        SshDiagnostics $sshDiagnostics
    ) {
        $this->api = $api;
        $this->activityService = $activityService;
        $this->config = $config;
        $this->git = $git;
        $this->localProject = $localProject;
        $this->questionHelper = $questionHelper;
        $this->relationships = $relationships;
        $this->selector = $selector;
        $this->ssh = $ssh;
        $this->sshDiagnostics = $sshDiagnostics;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['push'])
            ->setDescription('Push code to an environment')
            ->addArgument('source', InputArgument::OPTIONAL, 'The source ref: a branch name or commit hash', 'HEAD')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'The target branch name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Allow non-fast-forward updates')
            ->addOption('force-with-lease', null, InputOption::VALUE_NONE, 'Allow non-fast-forward updates, if the remote-tracking branch is up to date')
            ->addOption('set-upstream', 'u', InputOption::VALUE_NONE, 'Set the target environment as the upstream for the source branch')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Activate the environment before pushing')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Set the new environment parent (only used with --activate)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Set the environment type (only used with --activate )')
            ->addOption('no-clone-parent', null, InputOption::VALUE_NONE, "Do not clone the parent branch's data (only used with --activate)");

        $definition = $this->getDefinition();
        $this->selector->addEnvironmentOption($definition);
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);
        $this->ssh->configureInput($definition);

        $this->addExample('Push code to the current environment');
        $this->addExample('Push code, without waiting for deployment', '--no-wait');
        $this->addExample(
            'Push code and activate the environment as a child of \'develop\'',
            '--branch --parent develop'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $projectRoot = $this->selector->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $this->git->setDefaultRepositoryDir($projectRoot);

        // Validate the source argument.
        $source = $input->getArgument('source');
        if ($source === '') {
            $this->stdErr->writeln('The <error><source></error> argument cannot be specified as an empty string.');
            return 1;
        } elseif (strpos($source, ':') !== false
            || !($sourceRevision = $this->git->execute(['rev-parse', '--verify', $source]))) {
            $this->stdErr->writeln(sprintf('Invalid source ref: <error>%s</error>', $source));
            return 1;
        }

        $this->stdErr->writeln(
            sprintf('Source revision: %s', $sourceRevision),
            OutputInterface::VERBOSITY_VERY_VERBOSE
        );

        // Find the target branch name (--target, the name of the current
        // environment, or the Git branch name).
        if ($input->getOption('target')) {
            $target = $input->getOption('target');
        } elseif ($selection->hasEnvironment()) {
            $target = $selection->getEnvironment()->id;
        } elseif ($currentBranch = $this->git->getCurrentBranch()) {
            $target = $currentBranch;
        } else {
            $this->stdErr->writeln('Could not determine target environment name.');
            return 1;
        }

        $project = $selection->getProject();

        // Guard against accidental pushing to production.
        // @todo if/when the API provides "is this production" for an environment, use that instead of hardcoding branch names
        if (in_array($target, ['master', 'production', $project->default_branch])) {
            $questionText = sprintf(
                'Are you sure you want to push to the %s branch?',
                '<comment>' . $target . '</comment>' . ($target === 'production' ? '' : ' (production)')
            );
            if (!$this->questionHelper->confirm($questionText)) {
                return 1;
            }
        }

        // Determine whether the target environment is new.
        $targetEnvironment = $this->api->getEnvironment($target, $project);

        $activities = [];
        if ($target !== $project->default_branch) {
            // Determine whether to activate the environment.
            $activate = false;
            if (!$targetEnvironment || $targetEnvironment->status === 'inactive') {
                $activate = $input->getOption('branch')
                    || $input->getOption('activate');
                if (!$activate && $input->isInteractive()) {
                    $questionText = $targetEnvironment
                        ? sprintf('Do you want to activate the target environment %s?', $this->api->getEnvironmentLabel($targetEnvironment))
                        : sprintf('Create <info>%s</info> as an active environment?', $target);
                    $activate = $this->questionHelper->confirm($questionText);
                }
            }

            if ($activate) {
                // If activating, determine what the environment's parent should be.
                $parentId = $input->getOption('parent') ?: $this->findTargetParent($project, $targetEnvironment ?: null, $selection->hasEnvironment() ? $selection->getEnvironment() : null);

                // Determine the environment type.
                $type = $input->getOption('type');
                if ($type !== null && !$project->getEnvironmentType($type)) {
                    $this->stdErr->writeln('Environment type not found: <error>' . $type . '</error>');
                    return 1;
                } elseif ($type === null && $input->isInteractive()) {
                    $type = $this->askEnvironmentType($project);
                }

                // Activate the target environment. The deployment activity
                // will queue up behind whatever other activities are created
                // here.
                $activities = $this->activateTarget($target, $parentId, $project, !$input->getOption('no-clone-parent'), $type);
                if ($activities === false) {
                    return 1;
                }
            }
        }

        // Ensure the correct Git remote exists.
        $this->localProject->ensureGitRemote($projectRoot, $project->getGitUrl());

        // Build the Git command.
        $gitArgs = [
            'push',
            $this->config->get('detection.git_remote_name'),
            $source . ':refs/heads/' . $target,
        ];
        foreach (['force', 'force-with-lease', 'set-upstream'] as $option) {
            if ($input->getOption($option)) {
                $gitArgs[] = '--' . $option;
            }
        }

        // Build the SSH command to use with Git.
        $extraSshOptions = [];
        $env = [];
        if (!$this->activityService->shouldWait($input)) {
            $extraSshOptions['SendEnv'] = 'PLATFORMSH_PUSH_NO_WAIT';
            $env['PLATFORMSH_PUSH_NO_WAIT'] = '1';
        }

        $this->git->setExtraSshOptions($extraSshOptions);

        // Push.
        $this->stdErr->writeln(sprintf(
            'Pushing <info>%s</info> to the %s environment <info>%s</info>',
            $source,
            $targetEnvironment ? 'existing' : 'new',
            $target
        ));
        try {
            $this->git->execute($gitArgs, null, true, false, $env, true);
        } catch (ProcessFailedException $e) {
            $this->sshDiagnostics->diagnoseFailure($project->getGitUrl(), $e->getProcess());
            return 1;
        }

        // Wait if there are still activities.
        if ($this->activityService->shouldWait($input) && !empty($activities)) {
            $success = $this->activityService->waitMultiple($activities, $project);
            if (!$success) {
                return 1;
            }
        }

        // Clear some caches after pushing.
        $this->api->clearEnvironmentsCache($project->id);
        if ($selection->hasEnvironment()) {
            try {
                $sshUrl = $selection->getEnvironment()->getSshUrl();
                $this->relationships->clearCaches($sshUrl);
            } catch (EnvironmentStateException $e) {
                // Ignore environments with a missing SSH URL.
            }
        }

        return 0;
    }

    /**
     * Branches the target environment or activates it with the given parent.
     *
     * @param string $target
     * @param string $parentId
     * @param Project $project
     * @param bool $cloneParent
     * @param string|null $type
     *
     * @return false|array A list of activities, or false on failure.
     */
    private function activateTarget($target, $parentId, Project $project, $cloneParent, $type) {
        $parentEnvironment = $this->api->getEnvironment($parentId, $project);
        if (!$parentEnvironment) {
            throw new \RuntimeException("Parent environment not found: $parentId");
        }

        $targetEnvironment = $this->api->getEnvironment($target, $project);
        if ($targetEnvironment) {
            $activities = [];
            $updates = [];
            if ($targetEnvironment->parent !== $parentId) {
                $updates['parent'] = $parentId;
            }
            if (!$cloneParent && $targetEnvironment->getProperty('clone_parent_on_create', false, false)) {
                $updates['clone_parent_on_create'] = false;
            }
            if ($type !== null && $targetEnvironment->hasProperty('type') && $targetEnvironment->getProperty('type') !== $type) {
                $updates['type'] = $type;
            }
            if (!empty($updates)) {
                $activities = array_merge(
                    $activities,
                    $targetEnvironment->update($updates)->getActivities()
                );
            }
            $activities[] = $targetEnvironment->activate();
            $this->stdErr->writeln(sprintf(
                'Activated environment <info>%s</info>',
                $this->api->getEnvironmentLabel($targetEnvironment)
            ));
            $this->api->clearEnvironmentsCache($project->id);

            return $activities;
        }

        // For new environments, use branch() to create them as active in the first place.
        if (!$parentEnvironment->operationAvailable('branch', true)) {
            $this->stdErr->writeln(sprintf(
                'Operation not available: the environment %s cannot be branched.',
                $this->api->getEnvironmentLabel($parentEnvironment, 'error')
            ));

            if ($parentEnvironment->is_dirty) {
                $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
            } elseif (!$parentEnvironment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            }

            return false;
        }

        $activity = $parentEnvironment->branch($target, $target, $cloneParent, $type);
        $this->stdErr->writeln(sprintf(
            'Branched <info>%s</info>%s from parent %s',
            $target,
            $type !== null ? ' (type: <info>' . $type . '</info>)' : '',
            $this->api->getEnvironmentLabel($parentEnvironment)
        ));
        $this->debug(sprintf('Branch activity ID / state: %s / %s', $activity->id, $activity->state));

        $this->api->clearEnvironmentsCache($project->id);

        return [$activity];
    }

    /**
     * Asks the user for the environment type.
     *
     * @param Project $project
     *
     * @return string|null
     */
    private function askEnvironmentType(Project $project) {
        try {
            $types = $project->getEnvironmentTypes();
        } catch (BadResponseException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
                $this->debug('Cannot list environment types. The project probably does not yet support them.');
                return null;
            }
            throw $e;
        }
        $defaultId = null;
        $ids = [];
        foreach ($types as $type) {
            if ($type->id === 'development') {
                $defaultId = $type->id;
            }
            $ids[] = $type->id;
        }

        return $this->questionHelper->askInput('Environment type', $defaultId, $ids);
    }

    /**
     * Determines the parent of the target environment (for activate / branch).
     *
     * @param Project $project
     * @param Environment|null $targetEnvironment
     * @param Environment|null $selectedEnvironment
     *
     * @return string The parent environment ID.
     */
    private function findTargetParent(Project $project, Environment $targetEnvironment = null, Environment $selectedEnvironment = null) {
        if ($targetEnvironment && $targetEnvironment->parent) {
            return $targetEnvironment->parent;
        }

        $environments = $this->api->getEnvironments($project);
        if ($selectedEnvironment) {
            $defaultId = $selectedEnvironment->id;
        } else {
            $default = $this->api->getDefaultEnvironment($project);
            $defaultId = $default ? $default->id : null;
        }
        if (array_keys($environments) === [$defaultId]) {
            return $defaultId;
        }

        return $this->questionHelper->askInput('Parent environment', $defaultId, array_keys($environments));
    }
}
