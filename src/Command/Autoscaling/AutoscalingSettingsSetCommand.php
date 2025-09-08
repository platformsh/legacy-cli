<?php

namespace Platformsh\Cli\Command\Autoscaling;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Exception\EnvironmentStateException;
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
            ->setDescription('Set the autoscaling configuration of apps or workers in an environment')
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Name of the app or worker to configure autoscaling for')
            ->addOption('metric', 'm', InputOption::VALUE_REQUIRED, 'Name of the metric to use for triggering autoscaling')
            ->addOption('enabled', null, InputOption::VALUE_REQUIRED, 'Enable autoscaling based on the given metric')
            ->addOption('threshold-up', null, InputOption::VALUE_REQUIRED, 'Threshold over which service will be scaled up')
            ->addOption('duration-up', null, InputOption::VALUE_REQUIRED, 'Duration over which metric is evaluated against threshold for scaling up')
            ->addOption('cooldown-up', null, InputOption::VALUE_REQUIRED, 'Duration to wait before attempting to further scale up after a scaling event')
            ->addOption('threshold-down', null, InputOption::VALUE_REQUIRED, 'Threshold under which service will be scaled down')
            ->addOption('duration-down', null, InputOption::VALUE_REQUIRED, 'Duration over which metric is evaluated against threshold for scaling down')
            ->addOption('cooldown-down', null, InputOption::VALUE_REQUIRED, 'Duration to wait before attempting to further scale down after a scaling event')
            ->addOption('instances-min', null, InputOption::VALUE_REQUIRED, 'Minimum number of instances that will be scaled down to')
            ->addOption('instances-max', null, InputOption::VALUE_REQUIRED, 'Maximum number of instances that will be scaled up to')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes that would be made, without changing anything')
            ->addProjectOption()
            ->addEnvironmentOption();

        $helpLines = [
            'Configure automatic scaling for apps or workers in an environment.',
            '',
            sprintf('You can also configure resources statically by running: <info>%s resources:set</info>', $this->config()->get('application.executable'))
        ];
        if ($this->config()->has('service.autoscaling_help_url')) {
            $helpLines[] = '';
            $helpLines[] = 'For more information on autoscaling, see: <info>' . $this->config()->get('service.autoscaling_help_url') . '</info>';
        }
        $this->setHelp(implode("\n", $helpLines));

        $this->addExample('Enable autoscaling for an application using the default configuration', '--service app --metric cpu');
        $this->addExample('Enable autoscaling for an application specifying a minimum of instances at all times', '--service app --metric cpu --instances-min 3');
        $this->addExample('Enable autoscaling for an application specifying a maximum of instances at most', '--service app --metric cpu --instances-max 5');
        $this->addExample('Disable autoscaling on cpu for an application', '--service app --metric cpu --enabled false');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        if (!$this->api()->supportsAutoscaling($this->getSelectedProject())) {
            $this->stdErr->writeln(sprintf('The autoscaling API is not enabled for the project %s.', $this->api()->getProjectLabel($this->getSelectedProject(), 'comment')));
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

        $autoscalingSettings = $this->api()->getAutoscalingSettings($environment);
        if (!$autoscalingSettings) {
            $this->stdErr->writeln(\sprintf('Autoscaling support is not currently available on the environment: %s', $this->api()->getEnvironmentLabel($environment, 'error')));
            return 1;
        }
        $autoscalingSettings = $autoscalingSettings->getData();

        $services = array_merge($deployment->webapps, $deployment->workers);
        if (empty($services)) {
            $this->stdErr->writeln('No apps or workers found.');
            return 1;
        }

        // Get autoscaling default values
        $defaults = $autoscalingSettings['defaults'];

        // Validate the --service option.
        $service = $input->getOption('service');
        if ($service !== null) {
            $service = $this->validateService($service, $services);
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

        // Validate the --*-up options.
        $thresholdUp = $input->getOption('threshold-up');
        if ($thresholdUp !== null) {
            $thresholdUp = $this->validateThreshold($thresholdUp, 'threshold-up');
        }
        $durationUp = $input->getOption('duration-up');
        if ($durationUp !== null) {
            $durationUp = $this->validateDuration($durationUp);
        }
        $cooldownUp = $input->getOption('cooldown-up');
        if ($cooldownUp !== null) {
            $cooldownUp = $this->validateDuration($cooldownUp);
        }

        // Validate the --*-down options.
        $thresholdDown = $input->getOption('threshold-down');
        if ($thresholdDown !== null) {
            $thresholdDown = $this->validateThreshold($thresholdDown, 'threshold-down');
        }
        $durationDown = $input->getOption('duration-down');
        if ($durationDown !== null) {
            $durationDown = $this->validateDuration($durationDown);
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

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        // Check if we should show the interactive form
        $hasAnyOptions = $service !== null
            || $thresholdUp !== null
            || $durationUp !== null
            || $cooldownUp !== null
            || $thresholdDown !== null
            || $durationDown !== null
            || $cooldownDown !== null
            || $instancesMin !== null
            || $instancesMax !== null;

        $showInteractiveForm = $input->isInteractive() && !$hasAnyOptions;

        $updates = [];

        if ($showInteractiveForm) {
            // Interactive mode: let user select services and configure them
            $serviceNames = array_keys($services);

            if ($service === null) {
                // Ask user to select services to configure
                $default = $serviceNames[0];
                $text = 'Enter a number to choose an app or worker:' . "\n" . 'Default: <question>' . $default . '</question>';
                $selectedService = $questionHelper->choose($serviceNames, $text, 0);
                $service = $serviceNames[$selectedService];
            }

            $this->stdErr->writeln('');
            $this->stdErr->writeln('<options=bold>' . ucfirst($this->typeName($services[$service])) . ': </><options=bold,underscore>' . $service . '</>');
            $this->stdErr->writeln('');

            if ($metric === null) {
                // Ask for metric name
                $choices = array_keys($defaults['triggers']);
                $default = $choices[0];
                $text = 'Which metric should be used for autoscaling?' . "\n" . 'Default: <question>' . $default . '</question>';
                $choice = $questionHelper->choose($choices, $text, 0);
                $metric = $choices[$choice];
            }

            if ($thresholdUp === null || $durationUp === null || $cooldownUp === null) {
                $text = '<options=underscore>Settings for scaling <options=bold,underscore>up</></>';
                $this->stdErr->writeln($text);
                $this->stdErr->writeln('');

                if ($thresholdUp === null) {
                    // Ask for scaling up threshold
                    $default = $defaults['triggers'][$metric]['up']['threshold'];
                    $thresholdUp = $questionHelper->askInput('Enter the threshold', $default, [], function ($value) {
                        return $this->validateThreshold($value);
                    });
                    $this->stdErr->writeln('');
                }
                $updates[$service]['threshold-up'] = $thresholdUp;

                if ($durationUp === null) {
                    // Ask for scaling up duration
                    $choices = array_keys(self::$validDurations);
                    $defaultDuration = $defaults['triggers'][$metric]['up']['duration'];
                    $default = array_search($this->formatDuration($defaultDuration), $choices);
                    $text = 'Enter the duration of the evaluation period' . "\n" . 'Default: <question>' . $this->formatDuration($defaultDuration) . '</question>';
                    $choice = $questionHelper->choose($choices, $text, $default);
                    $durationUp = $this->validateDuration($choices[$choice]);
                }
                $updates[$service]['duration-up'] = $durationUp;

                if ($cooldownUp === null) {
                    // Ask for cool down period durations
                    $choices = array_flip(self::$validDurations);
                    $defaultDuration = $defaults['scale_cooldown']['up'];
                    $text = 'Enter the duration of the cool-down period' . "\n" . 'Default: <question>' . $this->formatDuration($defaultDuration) . '</question>';
                    $choice = $questionHelper->choose($choices, $text, $defaultDuration);
                    $cooldownUp = $this->validateDuration($choices[$choice]);
                }
                $updates[$service]['cooldown-up'] = $cooldownUp;
            }


            if ($thresholdDown === null || $durationDown === null || $cooldownDown === null) {
                $text = '<options=underscore>Settings for scaling <options=bold,underscore>down</></>';
                $this->stdErr->writeln($text);
                $this->stdErr->writeln('');

                if ($thresholdDown === null) {
                    // Ask for scaling down threshold
                    $default = $defaults['triggers'][$metric]['down']['threshold'];
                    $thresholdDown = $questionHelper->askInput('Enter the threshold', $default, [], function ($value) {
                        return $this->validateThreshold($value);
                    });
                    $this->stdErr->writeln('');
                }
                $updates[$service]['threshold-down'] = $thresholdDown;

                if ($durationDown === null) {
                    // Ask for scaling down duration
                    $choices = array_keys(self::$validDurations);
                    $defaultDuration = $defaults['triggers'][$metric]['down']['duration'];
                    $default = array_search($this->formatDuration($defaultDuration), $choices);
                    $text = 'Enter the duration of the evaluation period' . "\n" . 'Default: <question>' . $this->formatDuration($defaultDuration) . '</question>';
                    $choice = $questionHelper->choose($choices, $text, $default);
                    $durationDown = $this->validateDuration($choices[$choice]);
                }
                $updates[$service]['duration-down'] = $durationDown;

                if ($cooldownDown === null) {
                    $choices = array_flip(self::$validDurations);
                    $defaultDuration = $defaults['scale_cooldown']['down'];
                    $text = 'Enter the duration of the cool-down period' . "\n" . 'Default: <question>' . $this->formatDuration($defaultDuration) . '</question>';
                    $choice = $questionHelper->choose($choices, $text, $defaultDuration);
                    $cooldownDown = $this->validateDuration($choices[$choice]);
                }
                $updates[$service]['cooldown-down'] = $cooldownDown;
            }

            if ($instancesMin === null) {
                // Ask for instance count limits
                $instancesMin = $questionHelper->askInput('Enter the minimum number of instances', 1, [], function ($value) use ($instanceLimit) {
                    return $this->validateInstanceCount($value, $instanceLimit);
                });
                $this->stdErr->writeln('');
            }
            $updates[$service]['instances-min'] = $instancesMin;

            if ($instancesMax === null) {
                $instancesMax = $questionHelper->askInput('Enter the maximum number of instances', $instanceLimit, [], function ($value) use ($instanceLimit) {
                    return $this->validateInstanceCount($value, $instanceLimit);
                });
                $this->stdErr->writeln('');
            }
            $updates[$service]['instances-max'] = $instancesMax;

            if ($enabled !== null) {
                $updates[$service]['enabled'] = $enabled;
            }

            if (!empty($updates[$service])) {
                // since we have some changes, inject the metric name for them
                $updates[$service]['metric'] = $metric;
            }

        } else {
            // Non-interactive mode
            if ($service === null) {
                $this->stdErr->writeln('<error>The --service option is required when not running interactively.</error>');
                return 1;
            }

            if ($thresholdUp !== null) {
                $updates[$service]['threshold-up'] = $thresholdUp;
            }

            if ($durationUp !== null) {
                $updates[$service]['duration-up'] = $durationUp;
            }

            if ($thresholdDown !== null) {
                $updates[$service]['threshold-down'] = $thresholdDown;
            }

            if ($durationDown !== null) {
                $updates[$service]['duration-down'] = $durationDown;
            }

            if ($enabled !== null) {
                $updates[$service]['enabled'] = $enabled;
            }

            if ($instancesMin !== null) {
                $updates[$service]['instances-min'] = $instancesMin;
            }

            if ($instancesMax !== null) {
                $updates[$service]['instances-max'] = $instancesMax;
            }

            if ($cooldownUp !== null) {
                $updates[$service]['cooldown-up'] = $cooldownUp;
            }

            if ($cooldownDown !== null) {
                $updates[$service]['cooldown-down'] = $cooldownDown;
            }

            if (!empty($updates[$service])) {
                $metric = $this->validateMetric($metric, $defaults['triggers']);
                // since we have some changes, inject the metric name for them
                $updates[$service]['metric'] = $metric;
            }

        }

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

        $data = $this->makeAutoscalingSettingsData($updates);
        $this->api()->setAutoscalingSettings($environment, $data);

        return 0;
    }


    /**
     * Build an AutoscalingSettings instance.
     *
     * @param array $updates
     *
     * @return array
     */
    protected function makeAutoscalingSettingsData($updates)
    {
        $data = array('services' => []);

        foreach ($updates as $service => $serviceSettings) {
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

            $data['services'][$service] = $serviceData;
        }

        return $data;
    }


    /**
     * Summarizes all the changes that would be made.
     *
     * @param array $updates
     * @param array $settings
     *
     * @return void
     */
    private function summarizeChanges(array $updates, $settings)
    {
        $this->stdErr->writeln('<options=bold>Summary of changes:</>');
        foreach ($updates as $service => $serviceUpdates) {
            $this->summarizeChangesPerService($service, isset($settings[$service]) ? $settings[$service] : null, $serviceUpdates);
        }
    }

    /**
     * Summarizes changes per service.
     *
     * @param string $name The service name
     * @param array|null $current
     * @param array $updates
     *
     * @return void
     */
    private function summarizeChangesPerService($name, $current, array $updates)
    {
        $this->stdErr->writeln(sprintf('  <options=bold>Service: </><info>%s</info>', $name));

        $metric = $updates['metric'];
        $this->stdErr->writeln(sprintf('  Metric: <info>%s</info>', $metric));

        $action = 'remain';
        if (isset($updates['enabled'])) {
            if ($current['triggers'][$metric]['enabled'] != $updates['enabled']) {
                $action = 'become';
            }
        }
        $enabledNewText = $updates['enabled'] ? 'enabled' : 'disabled';
        $color = $updates['enabled'] ? 'green' : 'yellow';
        $status = '<fg=' . $color . '>'.$enabledNewText.'</>';
        $this->stdErr->writeln('    Autoscaling will ' . $action .  ': ' . $status);

        if (isset($updates['threshold-up']) || isset($updates['duration-up']) || isset($updates['cooldown-up'])) {
            $this->stdErr->writeln('    Scaling <options=bold>up</>');

            if (isset($updates['threshold-up'])) {
                $this->stdErr->writeln('      Threshold: ' . $this->formatChange(
                    isset($current['triggers'][$metric]['up']) ? $current['triggers'][$metric]['up']['threshold'] : null,
                    $updates['threshold-up']
                ));
            }
            if (isset($updates['duration-up'])) {
                $this->stdErr->writeln('      Duration: ' . $this->formatDurationChange(
                    isset($current['triggers'][$metric]['up']) ? $this->formatDuration($current['triggers'][$metric]['up']['duration']) : null,
                    $this->formatDuration($updates['duration-up'])
                ));
            }
            if (isset($updates['cooldown-up'])) {
                $this->stdErr->writeln('      Cooldown: ' . $this->formatDurationChange(
                    isset($current['scale_cooldown']) ? $this->formatDuration($current['scale_cooldown']['up']) : null,
                    $this->formatDuration($updates['cooldown-up'])
                ));
            }
        }

        if (isset($updates['threshold-down']) || isset($updates['duration-down']) || isset($updates['cooldown-down'])) {
            $this->stdErr->writeln('    Scaling <options=bold>down</>');

            $this->stdErr->writeln('      Threshold: ' . $this->formatChange(
                isset($current['triggers'][$metric]['down']) ? $current['triggers'][$metric]['down']['threshold'] : null,
                $updates['threshold-down']
            ));
            if (isset($updates['duration-down'])) {
                $this->stdErr->writeln('      Duration: ' . $this->formatDurationChange(
                    isset($current['triggers'][$metric]['down']) ? $this->formatDuration($current['triggers'][$metric]['down']['duration']) : null,
                    $this->formatDuration($updates['duration-down'])
                ));
            }
            if (isset($updates['cooldown-down'])) {
                $this->stdErr->writeln('      Cooldown: ' . $this->formatDurationChange(
                    isset($current['scale_cooldown']) ? $this->formatDuration($current['scale_cooldown']['down']) : null,
                    $this->formatDuration($updates['cooldown-down'])
                ));
            }
        }

        if (isset($updates['instances-min']) || isset($updates['instances-max'])) {
            $this->stdErr->writeln('    Instances');
            if (isset($updates['instances-min'])) {
                $this->stdErr->writeln('      Min: ' . $this->formatChange(
                    isset($current['instances']) ? $current['instances']['min'] : null,
                    $updates['instances-min']
                ));
            }

            if (isset($updates['instances-max'])) {
                $this->stdErr->writeln('      Max: ' . $this->formatChange(
                    isset($current['instances']) ? $current['instances']['max'] : null,
                    $updates['instances-max']
                ));
            }
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
        if (is_bool($value)) {
            return $value;
        }

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
     * @param string $context
     *
     * @throws InvalidArgumentException
     *
     * @return float
     */
    protected function validateThreshold($value, $context = '')
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

    private static $validDurations = [
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
     * @param string $context
     *
     * @throws InvalidArgumentException
     *
     * @return int
     */
    protected function validateDuration($value, $context = '')
    {
        if (!isset(self::$validDurations[$value])) {
            $durations = array_keys(self::$validDurations);
            $message = sprintf('Invalid duration <error>%s</error>: must be one of %s', $value, implode(', ', $durations));
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
     * @param string $context
     *
     * @throws InvalidArgumentException
     *
     * @return int
     */
    protected function validateInstanceCount($value, $limit, $context = '')
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
    protected function formatBoolean($value)
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
    protected function formatDuration($value)
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
            $changeText = $comparator($previousValue, $newValue) ? '<fg=green>increasing</>' : '<fg=yellow>decreasing</>';
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

