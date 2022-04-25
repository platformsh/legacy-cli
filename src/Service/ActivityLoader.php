<?php

namespace Platformsh\Cli\Service;

use DateTime;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Client\Model\Activities\HasActivitiesInterface;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityLoader
{

    private $stdErr;

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function __construct(OutputInterface $output)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * @return \Symfony\Component\Console\Output\OutputInterface
     */
    private function getProgressOutput()
    {
        return $this->stdErr->isDecorated() ? $this->stdErr : new NullOutput();
    }

    /**
     * Loads activities, looking at options set in a command input.
     *
     * The --state, --incomplete, --result, --start, --limit, and --type options will be processed.
     *
     * @param int|null $limit Limit the number of activities to return, regardless of input.
     * @param array $state Define the states to return, regardless of input.
     * @param string $withOperation Filters the resulting activities to those with the specified operation available.
     *
     * @return \Platformsh\Client\Model\Activity[]
     */
    public function loadFromInput(HasActivitiesInterface $apiResource, InputInterface $input, $limit = null, $state = [], $withOperation = '')
    {
        if ($state === [] && $input->hasOption('state')) {
            $state = ArrayArgument::getOption($input, 'state');
            if ($input->getOption('incomplete')) {
                if ($state && $state != [Activity::STATE_IN_PROGRESS, Activity::STATE_PENDING]) {
                    $this->stdErr->writeln('The <comment>--incomplete</comment> option implies <comment>--state in_progress,pending</comment>');
                }
                $state = [Activity::STATE_IN_PROGRESS, Activity::STATE_PENDING];
            }
        }
        if ($limit === null) {
            $limit = $input->hasOption('limit') ? $input->getOption('limit') : null;
        }
        $availableTypes = self::getAvailableTypes();
        $includeTypes = $input->hasOption('type') ? ArrayArgument::getOption($input, 'type') : [];
        $excludeTypes = $input->hasOption('exclude-type') ? ArrayArgument::getOption($input, 'exclude-type') : [];
        $types = [];
        foreach ($includeTypes as $includeType) {
            if (!\in_array($includeType, $availableTypes, true)) {
                $this->stdErr->writeln('Unrecognized activity type: <comment>' . $includeType . '</comment>');
            }
            if (\in_array($includeType, $excludeTypes, true)) {
                $this->stdErr->writeln('The <comment>--exclude-type</comment> and <comment>--type</comment> options conflict.');
            }
            $types[] = $includeType;
        }
        if (empty($types) && !empty($excludeTypes)) {
            $types = \array_filter($availableTypes, function ($type) use ($excludeTypes) {
                return !\in_array($type, $excludeTypes, true);
            });
        }
        $result = $input->hasOption('result') ? $input->getOption('result') : null;
        $startsAt = null;
        if ($input->hasOption('start') && $input->getOption('start') && !($startsAt = new DateTime($input->getOption('start')))) {
            $this->stdErr->writeln('Invalid --start date: <error>' . $input->getOption('start') . '</error>');
            return [];
        }
        $activities = $this->load($apiResource, $limit, $types, $startsAt, $state, $result);
        if ($withOperation) {
            $activities = array_filter($activities, function (Activity $activity) use ($withOperation) {
               return $activity->operationAvailable($withOperation);
            });
        }
        return $activities;
    }

    /**
     * Loads activities.
     *
     * @param HasActivitiesInterface    $apiResource
     * @param int|null    $limit
     * @param string[] $types
     * @param int|null    $startsAt
     * @param string|string[]|null    $state
     * @param string|string[]|null    $result
     * @param callable|null $stopCondition
     *   A test to perform on each activity. If it returns true, loading is stopped.
     *
     * @return \Platformsh\Client\Model\Activity[]
     */
    public function load(HasActivitiesInterface $apiResource, $limit = null, array $types = [], $startsAt = null, $state = null, $result = null, callable $stopCondition = null)
    {
        $progress = new ProgressBar($this->getProgressOutput());
        $progress->setMessage($types === ['environment.backup'] ? 'Loading backups...' : 'Loading activities...');
        $progress->setFormat($limit === null ? '%message% %current%' : '%message% %current%/%max%');
        $progress->start($limit);

        $activities = [];
        while ($limit === null || count($activities) < $limit) {
            if ($activity = end($activities)) {
                $startsAt = new DateTime($activity->created_at);
            }
            $nextActivities = $apiResource->getActivities($limit ? $limit - count($activities) : 0, $types, $startsAt, $state, $result);
            $new = false;
            foreach ($nextActivities as $activity) {
                if (!isset($activities[$activity->id])) {
                    $activities[$activity->id] = $activity;
                    $new = true;
                    if (isset($stopCondition) && $stopCondition($activity)) {
                        $progress->setProgress(count($activities));
                        break 2;
                    }
                }
            }
            if (!$new) {
                break;
            }
            $progress->setProgress(count($activities));
        }
        $progress->clear();

        return array_values($activities);
    }

    /**
     * Returns all the available options for the activity 'type' filter.
     *
     * @todo generate or fetch this from the API, when supported
     *
     * @return string[]
     */
    public static function getAvailableTypes()
    {
        return [
            'project.modify.title',
            'project.create',
            'project.domain.create',
            'project.domain.delete',
            'project.domain.update',
            'project.variable.create',
            'project.variable.delete',
            'project.variable.update',
            'environment.access.add',
            'environment.access.remove',
            'environment_type.access.create',
            'environment_type.access.delete',
            'environment_type.access.update',
            'environment.backup',
            'environment.restore',
            'environment.backup.delete',
            'environment.push',
            'environment.branch',
            'environment.activate',
            'environment.initialize',
            'environment.deactivate',
            'environment.synchronize',
            'environment.merge',
            'environment.redeploy',
            'environment.delete',
            'environment.route.create',
            'environment.route.delete',
            'environment.route.update',
            'environment.variable.create',
            'environment.variable.delete',
            'environment.variable.update',
            'environment.update.http_access',
            'environment.update.smtp',
            'environment.update.restrict_robots',
            'environment.subscription.update',
            'environment.cron',
            'environment.source-operation',
            'environment.certificate.renewal',
            'integration.bitbucket.fetch',
            'integration.bitbucket.register_hooks',
            'integration.bitbucket_server.fetch',
            'integration.bitbucket_server.register_hooks',
            'integration.github.fetch',
            'integration.gitlab.fetch',
            'integration.health.email',
            'integration.health.pagerduty',
            'integration.health.slack',
            'integration.webhook',
            'integration.script',
        ];
    }
}
