<?php

namespace Platformsh\Cli\Command\Autoscaling;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\AutoscalingSettings;
use Platformsh\Client\Model\Deployment\Service;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Deployment\Worker;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AutoscalingSettingsSetCommand extends CommandBase
{
    protected function configure()
    {
        $this->setName('autoscaling:set')
            ->setDescription('Set the autoscaling configuration of apps/workers on an environment')
            ->addOption('metric', null, InputOption::VALUE_REQUIRED, 'Name of the metric to use for triggering autoscaling')
            ->addOption('enabled', null, InputOption::VALUE_OPTIONAL, 'Enable autoscaling based on the given metric')
            ->addOption('threshold-up', null, InputOption::VALUE_OPTIONAL, 'Threshold over which service will be scaled up')
            ->addOption('threshold-down', null, InputOption::VALUE_OPTIONAL, 'Threshold under which service will be scaled down')
            ->addOption('duration-up', null, InputOption::VALUE_OPTIONAL, 'Duration over which metric is evaluated against threshold for scaling up')
            ->addOption('duration-down', null, InputOption::VALUE_OPTIONAL, 'Duration over which metric is evaluated against threshold for scaling down')
            ->addOption('cooldown-up', null, InputOption::VALUE_OPTIONAL, 'Duration to wait before attempting to further scale up after a scaling event')
            ->addOption('cooldown-down', null, InputOption::VALUE_OPTIONAL, 'Duration to wait before attempting to further scale down after a scaling event')
            ->addOption('instances-min', null, InputOption::VALUE_OPTIONAL, 'Minimum number of instances that will be scaled down to')
            ->addOption('instances-max', null, InputOption::VALUE_OPTIONAL, 'Maximum number of instances that will be scaled up to')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes that would be made, without changing anything')
            ->addProjectOption()
            ->addEnvironmentOption()
            ->addAppOption();

        $helpLines = ['Configure apps/workers for automatically scaling on an environment.'];
        if ($this->config()->has('service.autoscaling_help_url')) {
            $helpLines[] = '';
            $helpLines[] = 'For more information on autoscaling, see: <info>' . $this->config()->get('service.autoscaling_help_url') . '</info>';
        }
        $this->setHelp(implode("\n", $helpLines));

        $this->addExample('Enable autoscaling for the main application using the default configuration', '--app app --metric cpu');
        $this->addExample('Enable autoscaling for the main application specifying a minimum of instances at all times', '--app app --metric cpu --instances-min 3');
        $this->addExample('Enable autoscaling for the main application specifying a maximum of instances at most', '--app app --metric cpu --instances-max 5');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        if (!$this->api()->supportsAutoscaling($this->getSelectedProject())) {
            $this->stdErr->writeln(sprintf('The autoscaling feature is not enabled for the project %s.', $this->api()->getProjectLabel($this->getSelectedProject(), 'comment')));
            return 1;
        }

        $environment = $this->getSelectedEnvironment();

        try {
            $deployment = $this->api()->getCurrentDeployment($environment);
        } catch (EnvironmentStateException $e) {
            if ($environment->status === 'inactive') {
                $this->stdErr->writeln(sprintf('The environment %s is not active so autoscaling configuration cannot be set.', $this->api()->getEnvironmentLabel($environment, 'comment')));
                return 1;
            }
            throw $e;
        }

        try {
            $autoscalingSettings = $this->api()->getAutoscalingSettings($environment)->getData();
        } catch (EnvironmentStateException $e ) {
            throw $e;
        }

        $services = array_merge($deployment->webapps, $deployment->workers);
        if (empty($services)) {
            $this->stdErr->writeln('No apps/workers found');
            return 1;
        }

        // Get autoscaling default values
        $defaults = $autoscalingSettings['defaults'];

        // Validate the --app option.
        $app = $input->getOption('app');
        if ($app !== null) {
            $app = $this->validateService($app, $services);
        }

        // Validate the --metric option.
        $metric = $input->getOption('metric');
        if ($metric !== null) {
            $metric = $this->validateMetric($metric, $defaults['triggers']);
        }

        // Validate the --enabled option.
        $enabled = $input->getOption('enabled');
        if ($enabled !== null) {
            $enabled = $this->validateBoolean($enabled);
        }

        // Validate the --threshold-* options.
        $thresholdUp = $input->getOption('threshold-up');
        if ($thresholdUp !== null) {
            $thresholdUp = $this->validateThreshold($thresholdUp, 'threshold-up');
        }
        $thresholdDown = $input->getOption('threshold-down');
        if ($thresholdDown !== null) {
            $thresholdDown = $this->validateThreshold($thresholdDown, 'threshold-down');
        }

        // Validate the --duration-* options.
        $durationUp = $input->getOption('duration-up');
        if ($durationUp !== null) {
            $durationUp = $this->validateDuration($durationUp);
        }
        $durationDown = $input->getOption('duration-down');
        if ($durationDown !== null) {
            $durationDown = $this->validateDuration($durationDown);
        }

        // Validate the --cooldown-* options.
        $cooldownUp = $input->getOption('cooldown-up');
        if ($cooldownUp !== null) {
            $cooldownUp = $this->validateDuration($cooldownUp);
        }
        $cooldownDown = $input->getOption('cooldown-down');
        if ($cooldownDown !== null) {
            $cooldownDown = $this->validateDuration($cooldownDown);
        }

        // Validate the --instances-* options.
        $instanceLimit = $defaults['instances']['max'];
        $instancesMin = $input->getOption('instances-min');
        if ($instancesMin !== null) {
            $instancesMin = $this->validateInstanceCount($instancesMin, $instanceLimit, 'instances-min');
        }
        $instancesMax = $input->getOption('instances-max');
        if ($instancesMax !== null) {
            $instancesMax = $this->validateInstanceCount($instancesMax, $instanceLimit, 'instances-max');
        }

        $this->stdErr->writeln('');

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        // Check if we should show the interactive form
        $hasAnyOptions = $app !== null
            || $metric !== null
            || $enabled !== null
            || $thresholdUp !== null
            || $thresholdDown !== null
            || $durationUp !== null
            || $durationDown !== null
            || $cooldownUp !== null
            || $cooldownDown !== null
            || $instancesMin !== null
            || $instancesMax !== null;

        $showInteractiveForm = $input->isInteractive() && !$hasAnyOptions;

        $updates = [];

        if ($showInteractiveForm) {
            // Interactive mode: let user select services and configure them
            $serviceNames = array_keys($services);

            if ($app === null) {
                // Ask user to select services to configure
                $text = 'Enter a number to choose an app/worker:' . "\n" . 'Default: <question>' . $serviceNames[0] . '</question>';
                $selectedService = $questionHelper->choose($serviceNames, $text);
                $app = $serviceNames[$selectedService];
            }
            // Configure the selected service
            $serviceName = $app;
            $service = $services[$serviceName];

            $this->stdErr->writeln('');
            $this->stdErr->writeln('<options=bold>' . ucfirst($this->typeName($service)) . ': </><options=bold,underscore>' . $serviceName . '</>');
            $this->stdErr->writeln('');

            if ($metric === null) {
                // Ask for metric name
                $choices = array_keys($defaults['triggers']);
                $default = array_key_first($choices);
                $text = 'Enter the metric name for autoscaling:' . "\n" . 'Default: <question>' . $default . '</question>';
                $choice = $questionHelper->choose($choices, $text, $default);
                $metric = $choices[$choice];
            }

            if ($thresholdUp === null) {
                // Ask for scaling up threshold
                $default = $defaults['triggers'][$metric]['up']['threshold'];
                $thresholdUp = $questionHelper->askInput('Enter the threshold for scaling up', $default, [], function ($value) {
                    return $this->validateThreshold($value);
                });
                $this->stdErr->writeln('');
            }
            $updates[$serviceName]['threshold-up'] = $thresholdUp;

            if ($durationUp === null) {
                // Ask for scaling up duration
                $choices = array_keys(self::$validDurations);
                $defaultDuration = $defaults['triggers'][$metric]['up']['duration'];
                $default = array_search($this->formatDuration($defaultDuration), $choices);
                $text = 'Enter the duration for scaling up evaluation:' . "\n" . 'Default: <question>' . $default . '</question>';
                $choice = $questionHelper->choose($choices, $text, $default);
                $durationUp = $choices[$choice];
            }
            $updates[$serviceName]['duration-up'] = $durationUp;

            if ($thresholdDown === null) {
                // Ask for scaling down threshold
                $default = $defaults['triggers'][$metric]['down']['threshold'];
                $thresholdDown = $questionHelper->askInput('Enter the threshold for scaling down', $default, [], function ($value) {
                    return $this->validateThreshold($value);
                });
                $this->stdErr->writeln('');
            }
            $updates[$serviceName]['threshold-down'] = $thresholdDown;

            if ($durationDown === null) {
                // Ask for scaling down duration
                $choices = array_keys(self::$validDurations);
                $default = array_search($this->formatDuration($defaults['triggers'][$metric]['down']['duration']), $choices);
                $text = 'Enter the duration for scaling down evaluation:' . "\n" . 'Default: <question>' . $default . '</question>';
                $choice = $questionHelper->choose($choices, $text, $default);
                $durationDown = $choices[$choice];
            }
            $updates[$serviceName]['duration-down'] = $durationDown;

            if ($enabled === null) {
                // Ask for enabling autoscaling based on this metric
                $value = $questionHelper->confirm(sprintf('Enable autoscaling based on <options=bold,underscore>%s</>?', $metric), true);
                $enabled = $this->validateBoolean($value);
                $this->stdErr->writeln('');
            }
            $updates[$serviceName]['enabled'] = $enabled;

            if ($instancesMin === null) {
                // Ask for instance count limits
                $instancesMin = $questionHelper->askInput('Enter the minimum number of instances', 1, [], function ($value) {
                    return $this->validateInstanceCount($value, $instanceLimit);
                });
                $this->stdErr->writeln('');
            }
            $updates[$serviceName]['instances-min'] = $instancesMin;

            if ($instancesMax === null) {
                $instancesMax = $questionHelper->askInput('Enter the maximum number of instances', $instanceLimit, [], function ($value) {
                    return $this->validateInstanceCount($value, $instanceLimit);
                });
                $this->stdErr->writeln('');
            }
            $updates[$serviceName]['instances-max'] = $instancesMax;

            if ($cooldownUp === null) {
                // Ask for cool down period durations
                $choices = array_keys(self::$validDurations);
                $default = array_search($this->formatDuration($defaults['scale_cooldown']['up']), $choices);
                $text = 'Enter the duration of the cool-down period for scaling up:' . "\n" . 'Default: <question>' . $default . '</question>';
                $choice = $questionHelper->choose($choices, $text, $default);
                $cooldownUp = $choices[$choice];
            }
            $updates[$serviceName]['cooldown-up'] = $cooldownUp;

            if ($cooldownDown === null) {
                $choices = array_keys(self::$validDurations);
                $default = array_search($this->formatDuration($defaults['scale_cooldown']['down']), $choices);
                $text = 'Enter the duration of the cool-down period for scaling down:' . "\n" . 'Default: <question>' . $default . '</question>';
                $choice = $questionHelper->choose($choices, $text, $default);
                $cooldownDown = $choices[$choice];
            }
            $updates[$serviceName]['cooldown-down'] = $cooldownDown;

            if (!empty($updates[$serviceName])) {
                // since we have some changes, inject the metric name for them
                $updates[$serviceName]['metric'] = $metric;
            }

        } else {
            // Interactive mode: let user select services and configure them
            $serviceNames = array_keys($services);

            if ($app === null) {
                $this->stdErr->writeln('<error>The --app options is required when not running interactively.</error>');
                return 1;
            }
            // Configure the selected service
            $serviceName = $app;
            $service = $services[$serviceName];

            if ($thresholdUp !== null) {
                $updates[$serviceName]['threshold-up'] = $thresholdUp;
            }

            if ($durationUp !== null) {
                $updates[$serviceName]['duration-up'] = $durationUp;
            }

            if ($thresholdDown !== null) {
                $updates[$serviceName]['threshold-down'] = $thresholdDown;
            }

            if ($durationDown !== null) {
                $updates[$serviceName]['duration-down'] = $durationDown;
            }

            if ($enabled !== null) {
                $updates[$serviceName]['enabled'] = $enabled;
            }

            if ($instancesMin !== null) {
                $updates[$serviceName]['instances-min'] = $instancesMin;
            }

            if ($instancesMax !== null) {
                $updates[$serviceName]['instances-max'] = $instancesMax;
            }

            if ($cooldownUp !== null) {
                $updates[$serviceName]['cooldown-up'] = $cooldownUp;
            }

            if ($cooldownDown !== null) {
                $updates[$serviceName]['cooldown-down'] = $cooldownDown;
            }

            if (!empty($updates[$serviceName])) {
                $metric = $this->validateMetric($metric, $defaults['triggers']);
                // since we have some changes, inject the metric name for them
                $updates[$serviceName]['metric'] = $metric;
            }

        }

        $this->stdErr->writeln('');

        if (empty($updates)) {
            $this->stdErr->writeln('No autoscaling changes were provided: nothing to update');
            return 0;
        }

        $this->summarizeChanges($updates, $autoscalingSettings['services']);

        $this->debug('Raw updates: ' . json_encode($updates, JSON_UNESCAPED_SLASHES));

        if ($input->getOption('dry-run')) {
            return 0;
        }

        $this->stdErr->writeln('');

        $questionText = 'Are you sure you want to continue?';
        if (!$questionHelper->confirm($questionText)) {
            return 1;
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Setting the autoscaling configuration on the environment ' . $this->api()->getEnvironmentLabel($environment));

        $settings = $this->makeAutoscalingSettings($updates);
        $settings = $this->api()->setAutoscalingSettings($environment, $settings);

        return 0;
    }


    /**
     * Build an AutoscalingSettings instance.
     *
     * @param array $updates
     *
     * @return AutoscalingSettings
     */
    protected function makeAutoscalingSettings($updates)
    {
        $data = array('services' => []);

        foreach ($updates as $serviceName => $serviceSettings) {
            $serviceData = [];
            if (isset($serviceSettings['metric'])) {
                $triggerData = [];
                if (isset($serviceSettings['threshold-up'])) {
                    $triggerData['up'] = array('threshold' => $serviceSettings['threshold-up']);
                }
                if (isset($serviceSettings['duration-up'])) {
                    if (isset($triggerData['up'])) {
                        $triggerData['up']['duration'] = $serviceSettings['duration-up'];
                    } else {
                        $triggerData['up'] = array('duration' => $serviceSettings['duration-up']);
                    }
                }
                if (isset($serviceSettings['threshold-down'])) {
                    $triggerData['down'] = array('threshold' => $serviceSettings['threshold-down']);
                }
                if (isset($serviceSettings['duration-down'])) {
                    if (isset($triggerData['down'])) {
                        $triggerData['down']['duration'] = $serviceSettings['duration-down'];
                    } else {
                        $triggerData['down'] = array('duration' => $serviceSettings['duration-down']);
                    }
                }
                if (isset($serviceSettings['enabled'])) {
                    $triggerData['enabled'] = $serviceSettings['enabled'];
                }
                $serviceData['triggers'] = array($serviceSettings['metric'] => $triggerData);
            }

            if (isset($serviceSettings['cooldown-up']) || isset($serviceSettings['cooldown-down'])) {
                $cooldownData = [];
                if (isset($serviceSettings['cooldown-up'])) {
                    $cooldownData['up'] = $serviceSettings['cooldown-up'];
                }
                if (isset($serviceSettings['cooldown-down'])) {
                    $cooldownData['down'] = $serviceSettings['cooldown-down'];
                }
                $serviceData['scale_cooldown'] = $cooldownData;
            }

            if (isset($serviceSettings['instances-min']) || isset($serviceSettings['instances-max'])) {
                $instancesData = [];
                if (isset($serviceSettings['instances-min'])) {
                    $instancesData['min'] = $serviceSettings['instances-min'];
                }
                if (isset($serviceSettings['instances-max'])) {
                    $instancesData['max'] = $serviceSettings['instances-max'];
                }
                $serviceData['instances'] = $instancesData;
            }

            $data['services'][$serviceName] = $serviceData;
        }

        return new AutoscalingSettings($data);
    }


    /**
     * Summarizes all the changes that would be made.
     *
     * @param array $updates
     * @param array<string, WebApp|Worker|Service> $services
     * @return void
     */
    private function summarizeChanges(array $updates, $settings)
    {
        $this->stdErr->writeln('<options=bold>Summary of changes:</>');
        foreach ($updates as $serviceName => $serviceUpdates) {
            $this->summarizeChangesPerService($serviceName, $settings[$serviceName], $serviceUpdates);
        }
    }

    /**
     * Summarizes changes per service.
     *
     * @param string $name The service name
     * @param WebApp|Worker|Service $service
     * @param array $updates
     * @return void
     */
    private function summarizeChangesPerService($name, array $current, $updates)
    {
        $this->stdErr->writeln(sprintf('  <options=bold>Service: </><options=bold,underscore>%s</>', $name));

        $metric = $updates['metric'];
        $this->stdErr->writeln(sprintf('  Metric: %s', $metric));

        if (isset($updates['enabled'])) {
            $this->stdErr->writeln('    Enabled: ' . $this->formatChange(
                $current['triggers'][$metric] ? $this->formatBoolean($current['triggers'][$metric]['enabled']) : null,
                $this->formatBoolean($updates['enabled'])
            ));
        }
        if (isset($updates['threshold-up'])) {
            $this->stdErr->writeln('    Threshold (up): ' . $this->formatChange(
                $current['triggers'][$metric]['up'] ? $current['triggers'][$metric]['up']['threshold'] : null,
                $updates['threshold-up']
            ));
        }
        if (isset($updates['duration-up'])) {
            $this->stdErr->writeln('    Duration (up): ' . $this->formatDurationChange(
                $current['triggers'][$metric]['up'] ? $this->formatDuration($current['triggers'][$metric]['up']['duration']) : null,
                $updates['duration-up'],
            ));
        }
        if (isset($updates['threshold-down'])) {
            $this->stdErr->writeln('    Threshold (down): ' . $this->formatChange(
                $current['triggers'][$metric]['down'] ? $current['triggers'][$metric]['down']['threshold'] : null,
                $updates['threshold-down']
            ));
        }
        if (isset($updates['duration-down'])) {
            $this->stdErr->writeln('    Duration (down): ' . $this->formatDurationChange(
                $current['triggers'][$metric]['down'] ? $this->formatDuration($current['triggers'][$metric]['down']['duration']) : null,
                $updates['duration-down']
            ));
        }

        if (isset($updates['cooldown-up'])) {
            $this->stdErr->writeln('    Cooldown (up): ' . $this->formatDurationChange(
                $current['scale_cooldown'] ? $this->formatDuration($current['scale_cooldown']['up']) : null,
                $updates['cooldown-up']
            ));
        }
        if (isset($updates['cooldown-down'])) {
            $this->stdErr->writeln('    Cooldown (down): ' . $this->formatDurationChange(
                $current['scale_cooldown'] ? $this->formatDuration($current['scale_cooldown']['down']) : null,
                $updates['cooldown-down']
            ));
        }

        if (isset($updates['instances-min'])) {
            $this->stdErr->writeln('    Instances (min): ' . $this->formatChange(
                $current['instances'] ? $current['instances']['min'] : null,
                $updates['instances-min']
            ));
        }
        if (isset($updates['instances-max'])) {
            $this->stdErr->writeln('    Instances (max): ' . $this->formatChange(
                $current['instances'] ? $current['instances']['max'] : null,
                $updates['instances-max']
            ));
        }
    }

    /**
     * Validates a service name.
     *
     * @param string $value
     * @param array $services
     *
     * @throws InvalidArgumentException
     *
     * @return string
     */
    protected function validateService($value, $services)
    {
        if (array_key_exists($value, $services)) {
            return $value;
        }
        $serviceNames = array_keys($services);
        throw new InvalidArgumentException(sprintf('Invalid service name <error>%s</error>. Available services: %s', $value, implode(', ', $serviceNames)));
    }

    /**
     * Validates a metric name.
     *
     * @param string $value
     *
     * @throws InvalidArgumentException
     *
     * @return string
     */
    protected function validateMetric($value, $metrics)
    {
        if (array_key_exists($value, $metrics)) {
            return $value;
        }
        $metricNames = array_keys($metrics);
        throw new InvalidArgumentException(sprintf('Invalid metric name <error>%s</error>. Available metrics: %s', $value, implode(', ', $metricNames)));
    }

    /**
     * Validates a boolean value.
     *
     * @param float|int|string|bool $value
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    protected function validateBoolean($value)
    {
        switch ($value) {
            case "true":
            case "yes":
                return true;
            case "false":
            case "no":
                return false;
            default:
                throw new InvalidArgumentException(sprintf('Invalid value <error>%s</error>: must be one of true, yes, false, no', $value));
        }
    }

    /**
     * Validates a given threshold.
     *
     * @param float|int $value
     *
     * @throws InvalidArgumentException
     *
     * @return float
     */
    protected function validateThreshold($value, string $context = '')
    {
        $threshold = (float) $value;
        if ($threshold < 0) {
            $message = sprintf('Invalid threshold <error>%s</error>: must be 0 or greater', $value);
            if ($context) {
                $message .= sprintf(' for %s', $context);
            }
            throw new InvalidArgumentException($message);
        }
        if ($threshold > 100) {
            $message = sprintf('Invalid threshold <error>%s</error>: must be 100 or less', $value);
            if ($context) {
                $message .= sprintf(' for %s', $context);
            }
            throw new InvalidArgumentException($message);
        }
        return $threshold;
    }

    private static array $validDurations = [
        "1m" => 60,
        "2m" => 120,
        "5m" => 300,
        "10m" => 600,
        "30m" => 1800,
        "60m" => 3600,
    ];

    /**
     * Validates a given duration.
     *
     * @param string $value
     *
     * @throws InvalidArgumentException
     *
     * @return int
     */
    protected function validateDuration($value, string $context = '')
    {
        if (!isset(self::$validDurations[$value])) {
            $durations = array_keys(self::$validDurations);
            $message = sprintf('Invalid duration <error>%s</error>: must be one of %s', $value, implode(', ',$durations));
            if ($context) {
                $message .= sprintf(' for %s', $context);
            }
            throw new InvalidArgumentException($message);
        }
        return self::$validDurations[$value];
    }

    /**
     * Returns the service type name for a service.
     *
     * @param Service|WebApp|Worker $service
     *
     * @return string
     */
    protected function typeName($service)
    {
        if ($service instanceof WebApp) {
            return 'app';
        }
        if ($service instanceof Worker) {
            return 'worker';
        }
        return 'service';
    }

    /**
     * Validates a given instance count.
     *
     * @param string $value
     * @param int|null $limit
     *
     * @throws InvalidArgumentException
     *
     * @return int
     */
    protected function validateInstanceCount($value, $limit, string $context = '')
    {
        $count = (int) $value;
        if ($count != $value || $value <= 0) {
            $message = sprintf('Invalid instance count <error>%s</error>: it must be an integer greater than 0', $value);
            if ($context) {
                $message .= sprintf(' for %s', $context);
            }
            throw new InvalidArgumentException($message);
        }
        if ($limit !== null && $count > $limit) {
            $message = sprintf('The instance count <error>%d</error> exceeds the limit %d', $count, $limit);
            if ($context) {
                $message .= sprintf(' for %s', $context);
            }
            throw new InvalidArgumentException($message);
        }
        return $count;
    }

    /**
     * Formats a boolean value.
     *
     * @param bool $value
     *
     * @return string
     */
    protected function formatBoolean(bool $value)
    {
        return $value ? "true" : "false";
    }

    /**
     * Formats a duration.
     *
     * @param int $value
     *
     * @return string
     */
    protected function formatDuration(int $value)
    {
        $lookup = array_flip(self::$validDurations);
        if (!isset($lookup[$value])) {
            throw new InvalidArgumentException(sprintf('Invalid duration <error>%s</error>: must be one of %s', $value, implode(', ', array_keys($lookup))));
        }
        return $lookup[$value];
    }

    /**
     * Formats a change in a value.
     *
     * @param int|float|string|null $previousValue
     * @param int|float|string|null $newValue
     * @param string $suffix A unit suffix e.g. ' MB'
     *
     * @return string
     */
    protected function formatChange($previousValue, $newValue, $suffix = '', callable $comparator = null)
    {
        if ($previousValue === null || $newValue === $previousValue) {
            return sprintf('<info>%s%s</info>', $newValue, $suffix);
        }
        if ($comparator !== null) {
            $changeText = $comparator($newValue, $previousValue) ? '<fg=green>increasing</>' : '<fg=yellow>decreasing</>';
        } else if ($newValue === "true" || $newValue === "false") {
            $color = $newValue === "true" ? 'green' : 'yellow';
            $changeText = '<fg=' . $color . '>changing</>';
        } else {
            $changeText = $newValue > $previousValue ? '<fg=green>increasing</>' : '<fg=yellow>decreasing</>';
        }
        return sprintf(
            '%s from %s%s to <info>%s%s</info>',
            $changeText,
            $previousValue, $suffix,
            $newValue, $suffix
        );
    }

    /**
     * Formats a change in a duration.
     *
     * @param int|string $previousValue
     * @param int|string $newValue
     *
     * @return string
     */
    protected function formatDurationChange($previousValue, $newValue)
    {
        return $this->formatChange(
            $previousValue,
            $newValue,
            '',
            function ($previousValue, $newValue) {
                return self::$validDurations[$previousValue] < self::$validDurations[$newValue];
            }
        );
    }
}

