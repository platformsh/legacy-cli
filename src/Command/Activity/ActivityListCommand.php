<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityListCommand extends CommandBase
{
    protected static $defaultName = 'activity:list';

    private $activityService;
    private $api;
    private $config;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        Config $config,
        Selector $selector,
        Table $table,
        PropertyFormatter $formatter
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->table = $table;
        $this->formatter = $formatter;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['activities'])
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter activities by type')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check activities on all environments')
            ->setDescription('Get a list of activities for an environment or project');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
        $this->formatter->configureInput($definition);

        $this->addExample('List recent activities for the current environment')
             ->addExample('List all recent activities for the current project', '--all')
             ->addExample('List recent pushes', '--type environment.push')
             ->addExample('List pushes made before 15 March', '--type environment.push --start 2015-03-15');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input, $input->getOption('all'));

        $project = $selection->getProject();

        $startsAt = null;
        if ($input->getOption('start') && !($startsAt = strtotime($input->getOption('start')))) {
            $this->stdErr->writeln('Invalid date: <error>' . $input->getOption('start') . '</error>');
            return 1;
        }

        $limit = (int) $input->getOption('limit');

        if ($selection->hasEnvironment() && !$input->getOption('all')) {
            $environmentSpecific = true;
            $apiResource = $selection->getEnvironment();
        } else {
            $environmentSpecific = false;
            $apiResource = $project;
        }

        $activities = [];
        $type = $input->getOption('type');
        $progress = new ProgressBar($output->isDecorated() ? $this->stdErr : new NullOutput());
        $progress->setMessage('Loading activities...');
        $progress->setFormat('%message% %current% (max: %max%)');
        $progress->start($limit);
        while (count($activities) < $limit) {
            if ($activity = end($activities)) {
                $startsAt = strtotime($activity->created_at);
            }
            $nextActivities = $apiResource->getActivities($limit - count($activities), $type, $startsAt);
            if (!count($nextActivities)) {
                break;
            }
            foreach ($nextActivities as $activity) {
                $activities[$activity->id] = $activity;
            }
            $progress->setProgress(count($activities));
        }
        $progress->clear();

        /** @var \Platformsh\Client\Model\Activity[] $activities */
        if (!$activities) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        $rows = [];
        foreach ($activities as $activity) {
            $row = [
                new AdaptiveTableCell($activity->id, ['wrap' => false]),
                $this->formatter->format($activity['created_at'], 'created_at'),
                $this->activityService->getFormattedDescription($activity, !$this->table->formatIsMachineReadable()),
                $activity->getCompletionPercent() . '%',
                $this->activityService->formatState($activity->state),
                $this->activityService->formatResult($activity->result, !$this->table->formatIsMachineReadable()),
            ];
            if (!$environmentSpecific) {
                $row[] = implode(', ', $activity->environments);
            }
            $rows[] = $row;
        }

        $headers = ['ID', 'Created', 'Description', 'Progress', 'State', 'Result'];

        if (!$environmentSpecific) {
            $headers[] = 'Environment(s)';
        }

        if (!$this->table->formatIsMachineReadable()) {
            if ($environmentSpecific) {
                $this->stdErr->writeln(
                    sprintf(
                        'Activities for the environment <info>%s</info>:',
                        $apiResource->id
                    )
                );
            } else {
                $this->stdErr->writeln(
                    sprintf(
                        'Activities for the project <info>%s</info>:',
                        $this->api->getProjectLabel($project)
                    )
                );
            }
        }

        $this->table->render($rows, $headers);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->get('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for an activity, run: <info>%s activity:log [id]</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To view more information about an activity, run: <info>%s activity:get [id]</info>',
                $executable
            ));
        }

        return 0;
    }
}
