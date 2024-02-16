<?php
namespace Platformsh\Cli\Command\Environment;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentPushCommand extends CommandBase
{
    const PUSH_FAILURE_EXIT_CODE = 87;

    protected function configure()
    {
        $this
            ->setName('environment:push')
            ->setAliases(['push'])
            ->setDescription('Push code to an environment')
            ->addArgument('source', InputArgument::OPTIONAL, 'The Git source ref, e.g. a branch name or a commit hash.', 'HEAD')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'The target branch name. Defaults to the current branch.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Allow non-fast-forward updates')
            ->addOption('force-with-lease', null, InputOption::VALUE_NONE, 'Allow non-fast-forward updates, if the remote-tracking branch is up to date')
            ->addOption('set-upstream', 'u', InputOption::VALUE_NONE, 'Set the target environment as the upstream for the source branch. This will also set the target project as the remote for the local repository.')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Activate the environment. Paused environments will be resumed. This will ensure the environment is active even if no changes were pushed.')
            ->addHiddenOption('branch', null, InputOption::VALUE_NONE, 'DEPRECATED: alias of --activate')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Set the environment parent (only used with --activate)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Set the environment type (only used with --activate )')
            ->addOption('no-clone-parent', null, InputOption::VALUE_NONE, "Do not clone the parent branch's data (only used with --activate)");
        if ($this->config()->get('api.git_push_options')) {
            $this->addOption('resources-init', null, InputOption::VALUE_REQUIRED, 'Set the resources to use for new services: default, parent, minimum, or manual');
        }
        $this->addWaitOptions();
        $this->addProjectOption()
            ->addEnvironmentOption();
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Push code to the current environment');
        $this->addExample('Push code, without waiting for deployment', '--no-wait');
        $this->addExample(
            'Push code, branching or activating the environment as a child of \'develop\'',
            '--activate --parent develop'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->warnAboutDeprecatedOptions(['branch'], 'The option --%s is deprecated and will be removed in future. Use --activate, which has the same effect.');

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $gitRoot = $git->getRoot();

        if ($gitRoot === false) {
            $this->stdErr->writeln('This command can only be run from inside a Git repository.');
            return 1;
        }
        $git->setDefaultRepositoryDir($gitRoot);

        $this->validateInput($input, true);
        $project = $this->getSelectedProject();
        $currentProject = $this->getCurrentProject();
        $this->ensurePrintSelectedProject();
        $this->stdErr->writeln('');

        if ($currentProject && $currentProject->id !== $project->id) {
            $this->stdErr->writeln('The current repository is linked to another project: ' . $this->api()->getProjectLabel($currentProject, 'comment'));
            if ($input->getOption('set-upstream')) {
                $this->stdErr->writeln('It will be changed to link to the selected project.');
            } else {
                $this->stdErr->writeln('To link it to the selected project for future actions, use the: <comment>--set-upstream</comment> (<comment>-u</comment>) option');
                $this->stdErr->writeln(sprintf(
                    'Alternatively, run: <comment>%s set-remote %s</comment>',
                    $this->config()->get('application.executable'),
                    OsUtil::escapeShellArg($project->id)
                ));

            }
            $this->stdErr->writeln('');
        }

        // Validate the source argument.
        $source = $input->getArgument('source');
        if ($source === '') {
            $this->stdErr->writeln('The <error><source></error> argument cannot be specified as an empty string.');
            return 1;
        } elseif (strpos($source, ':') !== false
            || !($sourceRevision = $git->execute(['rev-parse', '--verify', $source]))) {
            $this->stdErr->writeln(sprintf('Invalid source ref: <error>%s</error>', $source));
            return 1;
        }

        $this->debug(sprintf('Source revision: %s', $sourceRevision));

        // Validate the --resources-init option.
        $resourcesInit = $input->hasOption('resources-init') ? $input->getOption('resources-init') : null;
        $resourcesInitOptions = ['default', 'parent', 'minimum', 'manual'];
        if ($resourcesInit !== null && !\in_array($resourcesInit, $resourcesInitOptions, true)) {
            $this->stdErr->writeln('The value for <error>--resources-init</error> must be one of: ' . \implode(', ', $resourcesInitOptions));
            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        // Find the target branch name (--target, the name of the current
        // environment, or the Git branch name).
        if ($input->getOption('target')) {
            $target = $input->getOption('target');
        } elseif ($this->hasSelectedEnvironment()) {
            $target = $this->getSelectedEnvironment()->id;
        } else {
            $allEnvironments = $this->api()->getEnvironments($project);
            $currentBranch = $git->getCurrentBranch();
            if ($currentBranch !== false && isset($allEnvironments[$currentBranch])) {
                $target = $currentBranch;
            } else {
                $default = $currentBranch !== false ? $currentBranch : null;
                $target = $questionHelper->askInput('Enter the target branch name', $default, array_keys($allEnvironments));
                if ($target === null) {
                    $this->stdErr->writeln('A target branch name (<error>--target</error>) is required.');
                    return 1;
                }
                $this->stdErr->writeln('');
            }
        }

        /** @var Environment|false $targetEnvironment The target environment, which may not exist yet. */
        $targetEnvironment = $this->api()->getEnvironment($target, $project);

        // Determine whether to activate the environment.
        $activateRequested = $this->determineShouldActivate($input, $project, $target, $targetEnvironment);
        $parentId = $type = null;
        if ($activateRequested) {
            // If activating, determine what the environment's parent should be.
            $parentId = $input->getOption('parent') ?: $this->findTargetParent($project, $targetEnvironment);

            // Determine the environment type.
            $type = $input->getOption('type');
            if ($type !== null && !$project->getEnvironmentType($type)) {
                $this->stdErr->writeln('Environment type not found: <error>' . $type . '</error>');
                return 1;
            } elseif ($targetEnvironment) {
                $type = $targetEnvironment->type;
            } elseif ($type === null && $input->isInteractive()) {
                $type = $this->askEnvironmentType($project);
            }
        }

        // Check if the environment may be a production one.
        $mayBeProduction = $type === 'production'
            || ($targetEnvironment && $targetEnvironment->type === 'production')
            || ($type === null && !$targetEnvironment && in_array($target, ['main', 'master', 'production', $project->default_branch], true));
        $otherProject = $currentProject && $currentProject->id !== $project->id;

        $codeAlreadyUpToDate = $sourceRevision === $targetEnvironment->head_commit;

        $projectLabel = $this->api()->getProjectLabel($project, $otherProject ? 'comment' : 'info');
        if ($targetEnvironment) {
            if ($codeAlreadyUpToDate) {
                $environmentLabel = $this->api()->getEnvironmentLabel($targetEnvironment);
                $this->stdErr->writeln(sprintf('The environment %s is already up to date with the source ref, <info>%s</info>.', $environmentLabel, $source));
                if (!$activateRequested || !in_array($targetEnvironment->status, ['inactive', 'paused'])) {
                    return 0;
                }
            } else {
                $environmentLabel = $this->api()->getEnvironmentLabel($targetEnvironment, $mayBeProduction ? 'comment' : 'info');
                $this->stdErr->writeln(sprintf('Pushing <info>%s</info> to the environment %s.', $source, $environmentLabel));
            }
            if ($activateRequested && $targetEnvironment->status === 'inactive') {
                $this->stdErr->writeln('The environment will be activated.');
            } elseif ($activateRequested && $targetEnvironment->status === 'paused') {
                $this->stdErr->writeln('The environment will be resumed.');
            } elseif ($activateRequested && $targetEnvironment->status === 'dirty') {
                $this->stdErr->writeln('The environment currently has an in-progress activity (it was likely already activated).');
            }
        } else {
            $targetLabel = $mayBeProduction ? '<comment>' . $target . '</comment>' : '<info>' . $target . '</info>';
            $this->stdErr->writeln(sprintf('Pushing <info>%s</info> to the branch %s of project %s', $source, $targetLabel, $projectLabel));
            if ($activateRequested) {
                $this->stdErr->writeln('It will be created as an active environment.');
            }
        }

        $this->stdErr->writeln('');

        if (!$questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }
        $this->stdErr->writeln('');

        $activities = [];
        $gitPushOptionsEnabled = $this->config()->get('api.git_push_options');

        // Activate or branch the target environment.
        //
        // The deployment activity from 'git push' will queue up behind
        // whatever other activities are created here.
        //
        // If Git Push Options are enabled, this will be skipped, and the
        // activation will happen automatically on the server side.
        if ($activateRequested && !$gitPushOptionsEnabled) {
            $activities = $this->ensureActive($target, $parentId, $project, !$input->getOption('no-clone-parent'), $type);
            if ($activities === false) {
                return 1;
            }
            $this->stdErr->writeln('');
        }

        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');

        $remoteName = $this->config()->get('detection.git_remote_name');

        // Map the current directory to the project.
        if ($input->getOption('set-upstream') && (!$currentProject || $currentProject->id !== $project->id)) {
            $this->stdErr->writeln(sprintf('Mapping the directory <info>%s</info> to the project %s', $gitRoot, $this->api()->getProjectLabel($project)));
            $this->stdErr->writeln('');
            $localProject->mapDirectory($gitRoot, $project);
            $currentProject = $project;
            $remoteRepoSpec = $remoteName;
        } elseif ($currentProject && $currentProject->id === $project->id) {
            // Ensure the current project's Git remote conforms.
            $localProject->ensureGitRemote($gitRoot, $project->getGitUrl());
            $remoteRepoSpec = $remoteName;
        } elseif ($git->getConfig("remote.$remoteName.url") === $project->getGitUrl()) {
            $remoteRepoSpec = $remoteName;
        } else {
            // If pushing to a project that isn't set as the current one, then
            // push directly to its URL instead of using a named Git remote.
            $remoteRepoSpec = $project->getGitUrl();
        }

        $log = '';
        if (!$codeAlreadyUpToDate) {
            // Build the Git command.
            $gitArgs = [
                'push',
                $remoteRepoSpec,
                $source . ':refs/heads/' . $target,
            ];
            foreach (['force', 'force-with-lease', 'set-upstream'] as $option) {
                if ($input->getOption($option)) {
                    $gitArgs[] = '--' . $option;
                }
            }
            if ($this->stdErr->isDecorated() && $this->isTerminal(STDERR)) {
                $gitArgs[] = '--progress';
            }
            if ($gitPushOptionsEnabled) {
                if ($activateRequested) {
                    $gitArgs[] = '--push-option=environment.status=active';
                }
                if ($parentId !== null) {
                    $gitArgs[] = '--push-option=environment.parent=' . $parentId;
                }
                if ($input->getOption('no-clone-parent')) {
                    $gitArgs[] = '--push-option=environment.clone_parent_on_create=false';
                }
                if ($resourcesInit !== null) {
                    $gitArgs[] = '--push-option=resources.init=' . $resourcesInit;
                }
            }

            // Build the SSH command to use with Git.
            $extraSshOptions = [];
            $env = [];
            if (!$this->shouldWait($input)) {
                $extraSshOptions[] = 'SendEnv PLATFORMSH_PUSH_NO_WAIT';
                $env['PLATFORMSH_PUSH_NO_WAIT'] = '1';
            }
            $git->setExtraSshOptions($extraSshOptions);

            // Perform the push, capturing the Process object so that the STDERR
            // output can be read.
            /** @var \Platformsh\Cli\Service\Shell $shell */
            $shell = $this->getService('shell');
            $process = $shell->executeCaptureProcess(\array_merge(['git'], $gitArgs), $gitRoot, false, false, $env + $git->setupSshEnv(), $this->config()->get('api.git_push_timeout'));
            if ($process->getExitCode() !== 0) {
                /** @var \Platformsh\Cli\Service\SshDiagnostics $diagnostics */
                $diagnostics = $this->getService('ssh_diagnostics');
                $diagnostics->diagnoseFailure($project->getGitUrl(), $process);
                return $process->getExitCode();
            }

            // Clear the environment cache after pushing.
            $this->api()->clearEnvironmentsCache($project->id);

            $log = $process->getErrorOutput();

            // Check the push log for services that need resources configured ("flexible resources").
            if (\strpos($log, 'Invalid deployment') !== false
                && \strpos($log, 'Resources must be configured') !== false) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('The push completed but resources must be configured before deployment can succeed.');
                if ($this->config()->isCommandEnabled('resources:set')) {
                    $cmd = 'resources:set';
                    if ($input->getOption('project')) {
                        $cmd .= ' -p ' . OsUtil::escapeShellArg($input->getOption('project'));
                    }
                    if ($input->getOption('target')) {
                        $cmd .= ' -e ' . OsUtil::escapeShellArg($input->getOption('target'));
                    } elseif ($input->getOption('environment')) {
                        $cmd .= ' -e ' . OsUtil::escapeShellArg($input->getOption('environment'));
                    }
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln(sprintf(
                        'Configure resources for the environment by running: <comment>%s %s</comment>',
                        $this->config()->get('application.executable'),
                        $cmd
                    ));
                }
                return self::PUSH_FAILURE_EXIT_CODE;
            }

            // Check the push log for other possible deployment error messages.
            $messages = $this->config()->getWithDefault('detection.push_deploy_error_messages', []);
            foreach ($messages as $message) {
                if (\strpos($log, $message) !== false) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln(\sprintf('The push completed but there was a deployment error ("<error>%s</error>").', $message));

                    return self::PUSH_FAILURE_EXIT_CODE;
                }
            }

            // The "Everything up-to-date" string might have dodgy
            // punctuation, but it is here to stay.
            // See: https://github.com/git/git/commit/80bdaba894b9868a74fa5931e3ce1ca074353b24
            if (strpos($log, "Everything up-to-date\n", $log) !== false) {
                $codeAlreadyUpToDate = true;
            }
        }

        // Compensate for push options not being able to take effect if no
        // changes were made.
        if ($gitPushOptionsEnabled && $activateRequested && $codeAlreadyUpToDate) {
            $activities = $this->ensureActive($target, $parentId, $project, !$input->getOption('no-clone-parent'), $type);
            if ($activities === false) {
                return 1;
            }
        }

        // Wait if there are still activities.
        if ($this->shouldWait($input) && !empty($activities)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $monitor */
            $monitor = $this->getService('activity_monitor');
            $success = $monitor->waitMultiple($activities, $project);
            if (!$success) {
                return 1;
            }
        }

        // Advise the user to set the project as the remote.
        if (!$currentProject && !$input->getOption('set-upstream')) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('To set the project as the remote for this repository, run:');
            $this->stdErr->writeln(sprintf('<info>%s set-remote %s</info>', $this->config()->get('application.executable'), OsUtil::escapeShellArg($project->id)));
        }

        return 0;
    }

    /**
     * Ensures the target environment is active.
     *
     * This may branch (creating a new environment), or resume or activate,
     * depending on the current state.
     *
     * @param string $target
     * @param string $parentId
     * @param Project $project
     * @param bool $cloneParent
     * @param string|null $type
     *
     * @return false|array A list of activities, or false on failure.
     */
    private function ensureActive($target, $parentId, Project $project, $cloneParent, $type) {
        $parentEnvironment = $this->api()->getEnvironment($parentId, $project);
        if (!$parentEnvironment) {
            throw new \RuntimeException("Parent environment not found: $parentId");
        }

        $targetEnvironment = $this->api()->getEnvironment($target, $project);
        if ($targetEnvironment) {
            $activities = [];
            $updates = [];
            if ($targetEnvironment->parent !== $parentId) {
                $updates['parent'] = $parentId;
            }
            if (!$cloneParent && $targetEnvironment->getProperty('clone_parent_on_create', false, false)) {
                $updates['clone_parent_on_create'] = false;
            }
            if ($type !== null && $targetEnvironment->type !== $type) {
                $updates['type'] = $type;
            }
            if (!empty($updates)) {
                $this->debug('Updating environment ' . $targetEnvironment->id . ' with properties: ' . json_encode($updates));
                $activities = array_merge(
                    $activities,
                    $targetEnvironment->update($updates)->getActivities()
                );
            }
            if ($targetEnvironment->status === 'dirty') {
                $targetEnvironment->refresh();
            }
            if ($targetEnvironment->status === 'inactive') {
                $this->debug('Activating inactive environment ' . $targetEnvironment->id);
                $activities = array_merge($activities, $targetEnvironment->runOperation('activate')->getActivities());
            } elseif ($targetEnvironment->status === 'paused') {
                $this->debug('Resuming paused environment ' . $targetEnvironment->id);
                $activities = array_merge($activities, $targetEnvironment->runOperation('resume')->getActivities());
            }
            $this->api()->clearEnvironmentsCache($project->id);

            return $activities;
        }

        // For new environments, use branch() to create them as active in the first place.
        if (!$parentEnvironment->operationAvailable('branch', true)) {
            $this->stdErr->writeln(sprintf(
                'Operation not available: the environment %s cannot be branched.',
                $this->api()->getEnvironmentLabel($parentEnvironment, 'error')
            ));

            if ($parentEnvironment->is_dirty) {
                $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
            } elseif (!$parentEnvironment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            }

            return false;
        }

        $params = [
            'name' => $target,
            'title' => $target,
            'clone_parent' => $cloneParent,
        ];
        if ($type !== null) {
            $params['type'] = $type;
        }
        $result = $parentEnvironment->runOperation('branch', 'POST', $params);
        $this->stdErr->writeln(sprintf(
            'Branched <info>%s</info>%s from parent %s',
            $target,
            $type !== null ? ' (type: <info>' . $type . '</info>)' : '',
            $this->api()->getEnvironmentLabel($parentEnvironment)
        ));

        $this->api()->clearEnvironmentsCache($project->id);

        return $result->getActivities();
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
            $types = $this->api()->getEnvironmentTypes($project);
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
        $questionHelper = $this->getService('question_helper');
        $type = $questionHelper->askInput('Environment type', $defaultId, $ids);
        $this->stdErr->writeln('');
        return $type;
    }

    /**
     * Determines the parent of the target environment (for activate / branch).
     *
     * @param Project          $project
     * @param Environment|false $targetEnvironment
     *
     * @return string The parent environment ID.
     */
    private function findTargetParent(Project $project, $targetEnvironment) {
        if ($targetEnvironment && $targetEnvironment->parent) {
            return $targetEnvironment->parent;
        }

        $environments = $this->api()->getEnvironments($project);
        if ($this->hasSelectedEnvironment()) {
            $defaultId = $this->getSelectedEnvironment()->id;
        } else {
            $default = $this->api()->getDefaultEnvironment($environments, $project);
            $defaultId = $default ? $default->id : null;
        }
        if (array_keys($environments) === [$defaultId]) {
            return $defaultId;
        }
        $questionHelper = $this->getService('question_helper');
        $parent = $questionHelper->askInput('Parent environment', $defaultId, array_keys($environments));
        $this->stdErr->writeln('');
        return $parent;
    }

    /**
     * Checks if the target environment should be activated, based on the user input or interactivity.
     *
     * @param InputInterface $input
     * @param Project $project
     * @param string $target
     * @param Environment|null $targetEnvironment
     *
     * @return bool
     */
    private function determineShouldActivate(InputInterface $input, Project $project, $target, Environment $targetEnvironment = null)
    {
        if ($target === $project->default_branch || ($targetEnvironment && $targetEnvironment->is_main)) {
            return false;
        }
        if ($input->getOption('branch') || $input->getOption('activate')) {
            return true;
        }
        if (!$input->isInteractive()) {
            return false;
        }
        if ($targetEnvironment && $targetEnvironment->is_dirty) {
            $targetEnvironment->refresh();
        }
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$targetEnvironment) {
            $questionText = sprintf('Create <info>%s</info> as an active environment?', $target);
        } elseif ($targetEnvironment->status === 'inactive') {
            $questionText = sprintf('Do you want to activate the target environment %s?', $this->api()->getEnvironmentLabel($targetEnvironment, 'info', false));
        } elseif ($targetEnvironment->status === 'paused') {
            $questionText = sprintf('Do you want to resume the paused target environment %s?', $this->api()->getEnvironmentLabel($targetEnvironment, 'info', false));
        } else {
            return false;
        }
        $activateRequested = $questionHelper->confirm($questionText);
        $this->stdErr->writeln('');
        return $activateRequested;
    }
}
