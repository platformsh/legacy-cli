<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentActivateCommand extends CommandBase
{
    protected static $defaultName = 'environment:activate';

    private $api;
    private $activityMonitor;
    private $questionHelper;
    private $selector;

    public function __construct(
        Api $api,
        ActivityMonitor $activityMonitor,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->api = $api;
        $this->activityMonitor = $activityMonitor;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Activate an environment')
            ->addArgument('environment', InputArgument::IS_ARRAY, 'The environment(s) to activate')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Set a new environment parent before activating');
        $this->addExample('Activate the environments "develop" and "stage"', 'develop stage');
        $this->selector->addAllOptions($this->getDefinition());
        $this->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        if ($selection->hasEnvironment()) {
            $toActivate = [$selection->getEnvironment()];
        } else {
            $environments = $this->api()->getEnvironments($selection->getProject());
            $environmentIds = $input->getArgument('environment');
            $toActivate = array_intersect_key($environments, array_flip($environmentIds));
            $notFound = array_diff($environmentIds, array_keys($environments));
            foreach ($notFound as $notFoundId) {
                $this->stdErr->writeln("Environment not found: <error>$notFoundId</error>");
            }
        }

        $success = $this->activateMultiple($toActivate, $selection->getProject(), $input, $this->stdErr);

        return $success ? 0 : 1;
    }

    /**
     * @param Environment[]   $environments
     * @param Project         $project
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function activateMultiple(array $environments, Project $project, InputInterface $input, OutputInterface $output)
    {
        $parentId = $input->getOption('parent');
        if ($parentId && !$this->api()->getEnvironment($parentId, $project)) {
            $this->stdErr->writeln(sprintf('Parent environment not found: <error>%s</error>', $parentId));
            return false;
        }

        $count = count($environments);
        $processed = 0;
        // Confirm which environments the user wishes to be activated.
        $process = [];
        foreach ($environments as $environment) {
            $environmentId = $environment->id;
            if (!$this->api()->checkEnvironmentOperation('activate', $environment)) {
                if ($environment->isActive()) {
                    $output->writeln("The environment <info>$environmentId</info> is already active.");
                    $count--;
                    continue;
                }

                $output->writeln(
                    "Operation not available: The environment <error>$environmentId</error> can't be activated."
                );
                continue;
            }
            $question = "Are you sure you want to activate the environment <info>$environmentId</info>?";
            if (!$this->questionHelper->confirm($question)) {
                continue;
            }
            $process[$environmentId] = $environment;
        }
        $activities = [];
        /** @var Environment $environment */
        foreach ($process as $environmentId => $environment) {
            try {
                if ($parentId && $parentId !== $environment->parent && $parentId !== $environmentId) {
                    $output->writeln(sprintf(
                        'Setting parent of environment <info>%s</info> to <info>%s</info>',
                        $environmentId,
                        $parentId
                    ));
                    $result = $environment->update(['parent' => $parentId]);
                    $activities = array_merge($activities, $result->getActivities());
                }
                $output->writeln(sprintf(
                    'Activating environment <info>%s</info>',
                    $environmentId
                ));
                $activities[] = $environment->activate();
                $processed++;
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }

        $success = $processed >= $count;

        if ($processed) {
            if ($this->shouldWait($input)) {
                $result = $this->activityMonitor->waitMultiple($activities, $project);
                $success = $success && $result;
            }
            $this->api()->clearEnvironmentsCache($project->id);
        }

        return $success;
    }
}
