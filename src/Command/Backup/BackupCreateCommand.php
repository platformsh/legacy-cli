<?php
namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\UserAccess\ProjectUserAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:create', description: 'Make a backup of an environment', aliases: ['backup'])]
class BackupCreateCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
            ->addOption('live', null, InputOption::VALUE_NONE,
                'Live backup: do not stop the environment.'
                . "\n" . 'If set, this leaves the environment running and open to connections during the backup.'
                . "\n" . 'This reduces downtime, at the risk of backing up data in an inconsistent state.'
            );
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
        $this->addHiddenOption('unsafe', null, InputOption::VALUE_NONE, 'Deprecated option: use --live instead');
        $this->setHiddenAliases(['snapshot:create', 'environment:backup']);
        $this->addExample('Make a backup of the current environment');
        $this->addExample('Request a backup (and exit quickly)', '--no-wait');
        $this->addExample('Make a backup avoiding downtime (but risking inconsistency)', '--live');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->warnAboutDeprecatedOptions(['unsafe']);
        $this->chooseEnvFilter = $this->filterEnvsMaybeActive();
        $this->validateInput($input);

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment->id;
        if (!$selectedEnvironment->operationAvailable('backup', true)) {
            $this->stdErr->writeln(
                "Operation not available: cannot create a backup of <error>$environmentId</error>"
            );

            if ($selectedEnvironment->is_dirty) {
                $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
            } elseif (!$selectedEnvironment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            } else {
                try {
                    if ($this->isUserAdmin($this->getSelectedProject(), $selectedEnvironment, $this->api()->getMyUserId())) {
                        $this->stdErr->writeln('You must be an administrator to create a backup.');
                    }
                } catch (\Exception $e) {
                    $this->debug('Error while checking access: ' . $e->getMessage());
                }
            }

            return 1;
        }

        $live = $input->getOption('live') || $input->getOption('unsafe');

        $this->stdErr->writeln(sprintf(
            'Creating a %s of %s.',
            $live ? '<info>live</info> backup' : 'backup',
            $this->api()->getEnvironmentLabel($selectedEnvironment, 'info', false)
        ));
        $this->stdErr->writeln('Note: this may delete an older backup if the quota has been reached.');
        $this->stdErr->writeln('');

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }

        $result = $selectedEnvironment->runOperation('backup', 'POST', ['safe' => !$live]);

        // Hold the activities as a reference as they may be updated during
        // waitMultiple() below, allowing the backup_name to be extracted.
        $activities = $result->getActivities();

        if ($this->shouldWait($input)) {
            // Strongly recommend using --no-wait in a cron job.
            if (!$this->isTerminal(STDIN)) {
                $this->stdErr->writeln(
                    '<comment>Warning:</comment> use the --no-wait (-W) option if you are running this in a cron job.'
                );
            }

            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($activities, $this->getSelectedProject());
            if (!$success) {
                return 1;
            }
        }

        foreach ($activities as $activity) {
            if ($activity->type === 'environment.backup' && !empty($activity->payload['backup_name'])) {
                $output->writeln(\sprintf('Backup name: <info>%s</info>', $activity->payload['backup_name']));
                break;
            }
        }

        return 0;
    }

    private function isUserAdmin(Project $project, Environment $environment, $userId)
    {
        if ($this->config()->get('api.centralized_permissions') && $this->config()->get('api.organizations')) {
            $client = $this->api()->getHttpClient();
            $endpointUrl = $project->getUri() . '/user-access';
            $userAccess = ProjectUserAccess::get($userId, $endpointUrl, $client);
            if (!$userAccess) {
                return false;
            }
            $roles = $userAccess->getEnvironmentTypeRoles();
            $role = isset($roles[$environment->type]) ? $roles[$environment->type] : $userAccess->getProjectRole();
            return $role === 'admin';
        }

        $type = $project->getEnvironmentType($environment->type);
        if (!$type) {
            throw new \RuntimeException('Failed to load environment type: ' . $environment->type);
        }
        $access = $type->getUser($userId);

        return $access && $access->role === 'admin';
    }
}
