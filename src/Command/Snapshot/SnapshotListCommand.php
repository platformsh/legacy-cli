<?php
namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotListCommand extends CommandBase
{

    protected static $defaultName = 'snapshot:list';

    private $activityService;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(
        ActivityService $activityService,
        PropertyFormatter $formatter,
        Selector $selector,
        Table $table
    ) {
        $this->activityService = $activityService;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['snapshots'])
            ->setDescription('List available snapshots of an environment')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of snapshots to list', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only snapshots created before this date will be listed');

        $definition = $this->getDefinition();
        $this->table->configureInput($definition);
        $this->formatter->configureInput($definition);
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);

        $this->addExample('List the most recent snapshots')
             ->addExample('List snapshots made before last week', "--start '1 week ago'");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environment = $this->selector->getSelection($input)->getEnvironment();

        $startsAt = null;
        if ($input->getOption('start') && !($startsAt = strtotime($input->getOption('start')))) {
            $this->stdErr->writeln('Invalid date: <error>' . $input->getOption('start') . '</error>');
            return 1;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln("Finding snapshots for the environment <info>{$environment->id}</info>");
        }

        $activities = $environment->getActivities($input->getOption('limit'), 'environment.backup', $startsAt);
        if (!$activities) {
            $this->stdErr->writeln('No snapshots found');
            return 1;
        }

        $headers = ['Created', 'Snapshot name', 'Progress', 'State', 'Result'];
        $rows = [];
        foreach ($activities as $activity) {
            $snapshot_name = !empty($activity->payload['backup_name']) ? $activity->payload['backup_name'] : 'N/A';
            $rows[] = [
                $this->formatter->format($activity->created_at, 'created_at'),
                new AdaptiveTableCell($snapshot_name, ['wrap' => false]),
                $activity->getCompletionPercent() . '%',
                $this->activityService->formatState($activity->state),
                $this->activityService->formatResult($activity->result, !$this->table->formatIsMachineReadable()),
            ];
        }

        $this->table->render($rows, $headers);
        return 0;
    }
}
