<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
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
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
            ->addOption(
                'live',
                null,
                InputOption::VALUE_NONE,
                'Live backup: do not stop the environment.'
                . "\n" . 'If set, this leaves the environment running and open to connections during the backup.'
                . "\n" . 'This reduces downtime, at the risk of backing up data in an inconsistent state.',
            );
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addHiddenOption('unsafe', null, InputOption::VALUE_NONE, 'Deprecated option: use --live instead');
        $this->setHiddenAliases(['snapshot:create', 'environment:backup']);
        $this->addExample('Make a backup of the current environment');
        $this->addExample('Request a backup (and exit quickly)', '--no-wait');
        $this->addExample('Make a backup avoiding downtime (but risking inconsistency)', '--live');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->warnAboutDeprecatedOptions(['unsafe']);
        $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));

        $selectedEnvironment = $selection->getEnvironment();
        $environmentId = $selectedEnvironment->id;
        if (!$selectedEnvironment->operationAvailable('backup', true)) {
            $this->stdErr->writeln(
                "Operation not available: cannot create a backup of <error>$environmentId</error>",
            );

            if ($selectedEnvironment->is_dirty) {
                $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
            } elseif (!$selectedEnvironment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            } else {
                try {
                    if ($this->isUserAdmin($selection->getProject(), $selectedEnvironment, $this->api->getMyUserId())) {
                        $this->stdErr->writeln('You must be an administrator to create a backup.');
                    }
                } catch (\Exception $e) {
                    $this->io->debug('Error while checking access: ' . $e->getMessage());
                }
            }

            return 1;
        }

        $live = $input->getOption('live') || $input->getOption('unsafe');

        $this->stdErr->writeln(sprintf(
            'Creating a %s of %s.',
            $live ? '<info>live</info> backup' : 'backup',
            $this->api->getEnvironmentLabel($selectedEnvironment, 'info', false),
        ));
        $this->stdErr->writeln('Note: this may delete an older backup if the quota has been reached.');
        $this->stdErr->writeln('');
        if (!$this->questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }

        $result = $selectedEnvironment->runOperation('backup', 'POST', ['safe' => !$live]);

        // Hold the activities as a reference as they may be updated during
        // waitMultiple() below, allowing the backup_name to be extracted.
        $activities = $result->getActivities();

        if ($this->activityMonitor->shouldWait($input)) {
            // Strongly recommend using --no-wait in a cron job.
            if (!$this->io->isTerminal(STDIN)) {
                $this->stdErr->writeln(
                    '<comment>Warning:</comment> use the --no-wait (-W) option if you are running this in a cron job.',
                );
            }

            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($activities, $selection->getProject());
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

    private function isUserAdmin(Project $project, Environment $environment, string $userId): bool
    {
        if ($this->config->getBool('api.centralized_permissions') && $this->config->getBool('api.organizations')) {
            $client = $this->api->getHttpClient();
            $endpointUrl = $project->getUri() . '/user-access';
            $userAccess = ProjectUserAccess::get($userId, $endpointUrl, $client);
            if (!$userAccess) {
                return false;
            }
            $roles = $userAccess->getEnvironmentTypeRoles();
            $role = $roles[$environment->type] ?? $userAccess->getProjectRole();
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
