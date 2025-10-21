<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use DateTime;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Model\Activities\HasActivitiesInterface;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

readonly class ActivityLoader
{
    private OutputInterface $stdErr;

    /**
     * @param OutputInterface $output
     */
    public function __construct(OutputInterface $output)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    private function getProgressOutput(): OutputInterface
    {
        return $this->stdErr->isDecorated() ? $this->stdErr : new NullOutput();
    }

    /**
     * Loads activities, looking at options set in a command input.
     *
     * The --state, --incomplete, --result, --start, --limit, and --type options will be processed.
     *
     * @param int|null $limit Limit the number of activities to return, regardless of input.
     * @param string[] $state Define the states to return, regardless of input.
     * @param string $withOperation Filters the resulting activities to those with the specified operation available.
     *
     * @return Activity[]
     */
    public function loadFromInput(HasActivitiesInterface $apiResource, InputInterface $input, ?int $limit = null, array $state = [], string $withOperation = ''): array
    {
        if ($state === [] && $input->hasOption('state')) {
            $state = ArrayArgument::getOption($input, 'state');
            if ($input->hasOption('incomplete') && $input->getOption('incomplete')) {
                if ($state && $state != [Activity::STATE_IN_PROGRESS, Activity::STATE_PENDING]) {
                    $this->stdErr->writeln('The <comment>--incomplete</comment> option implies <comment>--state in_progress,pending</comment>');
                }
                $state = [Activity::STATE_IN_PROGRESS, Activity::STATE_PENDING];
            }
        }
        if ($limit === null) {
            $limit = $input->hasOption('limit') ? (int) $input->getOption('limit') : null;
        }
        $availableTypes = self::getAvailableTypes();
        $requestedIncludeTypes = $input->hasOption('type') ? ArrayArgument::getOption($input, 'type') : [];
        $requestedExcludeTypes = $input->hasOption('exclude-type') ? ArrayArgument::getOption($input, 'exclude-type') : [];
        $typesToExclude = [];
        foreach ($requestedExcludeTypes as $requestedExcludeType) {
            // Make the first part of the type optional.
            $toExclude = Wildcard::select($availableTypes, [$requestedExcludeType]) ?: Wildcard::select($availableTypes, ['%.' . $requestedExcludeType]);
            if (empty($toExclude)) {
                $this->stdErr->writeln('Unrecognized activity type to exclude: <comment>' . $requestedExcludeType . '</comment>');
                $typesToExclude[] = $requestedExcludeType;
            } else {
                $typesToExclude = array_merge($typesToExclude, $toExclude);
            }
        }
        $typesFilter = [];
        foreach ($requestedIncludeTypes as $requestedIncludeType) {
            // Make the first part of the type optional.
            $toInclude = Wildcard::select($availableTypes, [$requestedIncludeType]) ?: Wildcard::select($availableTypes, ['%.' . $requestedIncludeType]);
            if (empty($toInclude)) {
                $this->stdErr->writeln('Unrecognized activity type to include: <comment>' . $requestedIncludeType . '</comment>');
                $typesFilter[] = $requestedIncludeType;
            } else {
                $typesFilter = array_merge($typesFilter, $toInclude);
            }
            if (\in_array($requestedIncludeType, $requestedExcludeTypes, true) || array_intersect($toInclude, $typesToExclude)) {
                $this->stdErr->writeln('The <comment>--exclude-type</comment> and <comment>--type</comment> options conflict.');
            }
        }
        if (empty($typesFilter) && !empty($typesToExclude)) {
            $typesFilter = \array_filter($availableTypes, fn($type): bool => !\in_array($type, $typesToExclude, true));
        }
        if (!empty($typesFilter) && $this->stdErr->isDebug()) {
            $this->stdErr->writeln('<options=reverse>DEBUG</> Selected activity type(s): ' . implode(',', $typesFilter));
        }
        $result = $input->hasOption('result') ? $input->getOption('result') : null;
        $startsAt = null;
        if ($input->hasOption('start') && $input->getOption('start')) {
            $startsAt = new DateTime($input->getOption('start'));
        }
        $activities = $this->load($apiResource, $limit, $typesFilter, $startsAt, $state, $result);
        if ($withOperation) {
            $activities = array_filter($activities, fn(Activity $activity): bool => $activity->operationAvailable($withOperation));
        }
        return $activities;
    }

    /**
     * Loads activities.
     *
     * @param HasActivitiesInterface    $apiResource
     * @param int|null    $limit
     * @param string[] $types
     * @param int|DateTime|null    $startsAt
     * @param string|string[]|null    $state
     * @param string|string[]|null    $result
     * @param callable|null $stopCondition
     *   A test to perform on each activity. If it returns true, loading is stopped.
     *
     * @return Activity[]
     */
    public function load(HasActivitiesInterface $apiResource, ?int $limit = null, array $types = [], int|DateTime|null $startsAt = null, array|string|null $state = null, array|string|null $result = null, ?callable $stopCondition = null): array
    {
        $progress = new ProgressBar($this->getProgressOutput());
        $progress->setMessage('Loading activities...');
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
    public static function getAvailableTypes(): array
    {
        return [
            'environment.access.add',
            'environment.access.remove',
            'environment.activate',
            'environment.backup',
            'environment.backup.delete',
            'environment.branch',
            'environment.certificate.renewal',
            'environment.cron',
            'environment.deactivate',
            'environment.delete',
            'environment.deploy',
            'environment.domain.create',
            'environment.domain.delete',
            'environment.domain.update',
            'environment.initialize',
            'environment.merge',
            'environment.merge-pr',
            'environment.operation',
            'environment.pause',
            'environment.push',
            'environment.redeploy',
            'environment.restore',
            'environment.resume',
            'environment.resources.update',
            'environment.update.privileged_configuration',
            'environment.version.create',
            'environment.version.update',
            'environment.version.delete',
            'environment.route.create',
            'environment.route.delete',
            'environment.route.update',
            'environment.source-operation',
            'environment.subscription.update',
            'environment.synchronize',
            'environment.update.http_access',
            'environment.update.restrict_robots',
            'environment.update.smtp',
            'environment.variable.create',
            'environment.variable.delete',
            'environment.variable.update',
            'environment_type.access.create',
            'environment_type.access.delete',
            'environment_type.access.update',
            'integration.bitbucket.fetch',
            'integration.bitbucket.register_hooks',
            'integration.bitbucket_server.fetch',
            'integration.bitbucket_server.register_hooks',
            'integration.github.fetch',
            'integration.gitlab.fetch',
            'integration.health.email',
            'integration.health.pagerduty',
            'integration.health.slack',
            'integration.health.webhook',
            'integration.script',
            'integration.webhook',
            'maintenance.upgrade',
            'project.clear_build_cache',
            'project.create',
            'project.domain.create',
            'project.domain.delete',
            'project.domain.update',
            'project.metrics.enable',
            'project.metrics.update',
            'project.metrics.disable',
            'project.modify.title',
            'project.variable.create',
            'project.variable.delete',
            'project.variable.update',
        ];
    }
}
