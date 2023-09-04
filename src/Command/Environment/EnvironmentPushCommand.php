<?php
namespace Platformsh\Cli\Command\Environment;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Exception\EnvironmentStateException;
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
            ->addArgument('source', InputArgument::OPTIONAL, 'The source ref: a branch name or commit hash', 'HEAD')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'The target branch name. Defaults to the current branch.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Allow non-fast-forward updates')
            ->addOption('force-with-lease', null, InputOption::VALUE_NONE, 'Allow non-fast-forward updates, if the remote-tracking branch is up to date')
            ->addOption('set-upstream', 'u', InputOption::VALUE_NONE, 'Set the target environment as the upstream for the source branch. This will also set the target project as the remote for the local repository.')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Activate the environment before pushing')
            ->addHiddenOption('branch', null, InputOption::VALUE_NONE, 'DEPRECATED: alias of --activate')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Set the new environment parent (only used with --activate)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Set the environment type (only used with --activate )')
            ->addOption('no-clone-parent', null, InputOption::VALUE_NONE, "Do not clone the parent branch's data (only used with --activate)");
        $this->addWaitOptions();
        $this->addProjectOption()
            ->addEnvironmentOption();
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Push code to the current environment');
        $this->addExample('Push code, without waiting for deployment', '--no-wait');
        $this->addExample(
            'Push code, first branching or activating the environment as a child of \'develop\'',
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
        $activateRequested = false;
        $parentId = $type = null;
        if ($target !== $project->default_branch) {
            $activateRequested = $input->getOption('branch') || $input->getOption('activate');
            if (!$activateRequested && (!$targetEnvironment || $targetEnvironment->status === 'inactive') && $input->isInteractive()) {
                $questionText = $targetEnvironment
                    ? sprintf('Do you want to activate the target environment %s?', $this->api()->getEnvironmentLabel($targetEnvironment, 'info', false))
                    : sprintf('Create <info>%s</info> as an active environment?', $target);
                $activateRequested = $questionHelper->confirm($questionText);
                $this->stdErr->writeln('');
            }
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

                $this->stdErr->writeln('');
            }
        }

        // Check if the environment may be a production one.
        $mayBeProduction = $type === 'production'
            || ($targetEnvironment && $targetEnvironment->type === 'production')
            || ($type === null && !$targetEnvironment && in_array($target, ['main', 'master', 'production', $project->default_branch], true));
        $otherProject = $currentProject && $currentProject->id !== $project->id;

        $projectLabel = $this->api()->getProjectLabel($project, $otherProject ? 'comment' : 'info');
        if ($targetEnvironment) {
            $environmentLabel = $this->api()->getEnvironmentLabel($targetEnvironment, $mayBeProduction ? 'comment' : 'info');
            $this->stdErr->writeln(sprintf('Pushing <info>%s</info> to the environment %s of project %s', $source, $environmentLabel, $projectLabel));
            if ($activateRequested && !$targetEnvironment->isActive()) {
                $this->stdErr->writeln('The environment will be activated.');
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
            $activities = $this->activateTarget($target, $parentId, $project, !$input->getOption('no-clone-parent'), $type);
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
            if ($input->getOption('branch') || $input->getOption('activate')) {
                $gitArgs[] = '--push-option=environment.status=active';
            }
            if ($parentId !== null) {
                $gitArgs[] = '--push-option=environment.parent=' . $parentId;
            }
            if ($input->getOption('no-clone-parent')) {
                $gitArgs[] = '--push-option=environment.clone_parent_on_create=false';
            }
        }

        // Build the SSH command to use with Git.
        $extraSshOptions = [];
        $env = [];
        if (!$this->shouldWait($input)) {
            $extraSshOptions['SendEnv'] = 'PLATFORMSH_PUSH_NO_WAIT';
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

        // Check the push log for possible deployment error messages.
        $log = $process->getErrorOutput();
        $messages = $this->config()->getWithDefault('detection.push_deploy_error_messages', []);
        foreach ($messages as $message) {
            if (\strpos($log, $message) !== false) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('The "git push" completed but there was a deployment error ("<error>%s</error>").', $message));

                return self::PUSH_FAILURE_EXIT_CODE;
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
                $activities = array_merge(
                    $activities,
                    $targetEnvironment->update($updates)->getActivities()
                );
            }
            if (!$targetEnvironment->isActive()) {
                $activities = array_merge($activities, $targetEnvironment->runOperation('activate')->getActivities());
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

        return $questionHelper->askInput('Environment type', $defaultId, $ids);
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
            $default = $this->api()->getDefaultEnvironment($project);
            $defaultId = $default ? $default->id : null;
        }
        if (array_keys($environments) === [$defaultId]) {
            return $defaultId;
        }
        $questionHelper = $this->getService('question_helper');

        return $questionHelper->askInput('Parent environment', $defaultId, array_keys($environments));
    }
}
