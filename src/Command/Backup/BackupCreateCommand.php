<?php
namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupCreateCommand extends CommandBase
{
    protected static $defaultName = 'backup:create|backup';
    protected static $defaultDescription = 'Make a backup of an environment';

    private $api;
    private $activityService;
    private $selector;

    public function __construct(
        Api $api,
        ActivityService $activityService,
        Selector $selector
    ) {
        $this->api = $api;
        $this->activityService = $activityService;
        $this->selector = $selector;
        parent::__construct();
        $this->setHiddenAliases(['snapshot:create', 'environment:backup']);
    }

    protected function configure()
    {
        $this->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
            ->addOption('live', null, InputOption::VALUE_NONE,
                'Live backup: do not stop the environment.'
                . "\n" . 'If set, this leaves the environment running and open to connections during the backup.'
                . "\n" . 'This reduces downtime, at the risk of backing up data in an inconsistent state.'
            );
        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);
        $this->addOption('unsafe', null, InputOption::VALUE_NONE, 'Deprecated option: use --live instead');
        $this->addExample('Make a backup of the current environment');
        $this->addExample('Request a backup (and exit quickly)', '--no-wait');
        $this->addExample('Make a backup avoiding downtime (but risking inconsistency)', '--live');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // TODO
        //$this->warnAboutDeprecatedOptions(['unsafe']);
        $selection = $this->selector->getSelection($input);

        $selectedEnvironment = $selection->getEnvironment();
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
                    $access = $selectedEnvironment->getUser($this->api->getMyUserId());
                    if ($access->role !== 'admin') {
                        $this->stdErr->writeln('You must be an administrator to create a backup.');
                    }
                } catch (\InvalidArgumentException $e) {
                    // Suppress exceptions when the 'access' API is not available for this environment.
                }
            }

            return 1;
        }

        $live = $input->getOption('live') || $input->getOption('unsafe');

        $activity = $selectedEnvironment->backup($live);

        if ($live) {
            $this->stdErr->writeln("Creating a <info>live</info> backup of <info>$environmentId</info>");
        } else {
            $this->stdErr->writeln("Creating a backup of <info>$environmentId</info>");
        }

        if ($this->activityService->shouldWait($input)) {
            // Strongly recommend using --no-wait in a cron job.
            if (!$this->isTerminal(STDIN)) {
                $this->stdErr->writeln(
                    '<comment>Warning:</comment> use the --no-wait (-W) option if you are running this in a cron job.'
                );
            }

            $success = $this->activityService->waitAndLog($activity);
            if (!$success) {
                return 1;
            }
        }

        if (!empty($activity['payload']['backup_name'])) {
            $name = $activity['payload']['backup_name'];
            $output->writeln("Backup name: <info>$name</info>");
        }

        return 0;
    }
}
