<?php

namespace Platformsh\Cli\Command\Autoscaling;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\AutoscalingSettings;
use Platformsh\Client\Model\Deployment\Service;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Deployment\Worker;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TypeError;

class AutoscalingSettingsSetCommand extends CommandBase
{
    protected function configure()
    {
        $this->setName('autoscaling:set')
            ->setDescription('Set the autoscaling configuration of apps/workers on an environment')
            ->addOption('name', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Name of the app/worker to configure autoscaling for')
            ->addOption('metric', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Name of the metric to use for triggering autoscaling')
            ->addOption('enabled', null, InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'Enable autoscaling based on the given metric')
            ->addOption('threshold-up', null, InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'Threshold over which service will be scaled up')
            ->addOption('threshold-down', null, InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'Threshold under which service will be scaled down')
            ->addOption('duration-up', null, InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'Duration over which metric is evaluated against threshold for scaling up')
            ->addOption('duration-down', null, InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'Duration over which metric is evaluated against threshold for scaling down')
            ->addOption('cooldown-up', null, InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'Duration to wait before attempting to further scale up after a scaling event')
            ->addOption('cooldown-down', null, InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'Duration to wait before attempting to further scale down after a scaling event')
            ->addOption('instances-min', null, InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'Minimum number of instances that will be scaled down to')
            ->addOption('instances-max', null, InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'Maximum number of instances that will be scaled up to')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes that would be made, without changing anything')
            ->addProjectOption()
            ->addEnvironmentOption();

        $helpLines = [
            'Configure thresholds used for automatically scaling apps and workers on an environment.',
            '',
            'If the same app/worker is specified on the command line multiple times, only the final value will be used.'
        ];
        if ($this->config()->has('service.autoscaling_help_url')) {
            $helpLines[] = '';
            $helpLines[] = 'For more information on autoscaling, see: <info>' . $this->config()->get('service.autoscaling_help_url') . '</info>';
        }
        $this->setHelp(implode("\n", $helpLines));

        $this->addExample('Enable autoscaling for the main application using the default configuration', '--service app --metric cpu');
        $this->addExample('Enable autoscaling for the main application specifying a minimum of instances at all times', '--service app --metric cpu --instances-min 3');
        $this->addExample('Enable autoscaling for the main application specifying a maximum of instances at most', '--service app --metric cpu --instances-max 5');
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
        $defaults = $autoscalingSettings["defaults"];

        // Validate the --name option.
        list($name, $errored) = $this->parseSetting($input, 'name', $services, function ($v, $serviceName, $service) use ($deployment) {
            return $this->validateService($v, $services);
        });

        // Validate the --metric option.
        list($metric, $metricsErrored) = $this->parseSetting($input, 'metric', $services, function ($v, $serviceName, $service) use ($deployment) {
            return $this->validateMetric($v, $defaults['triggers']);
        });
        $errored = $errored || $metricsErrored;

        // Validate the --enabled option.
        list($enabled, $enabledErrored) = $this->parseSetting($input, 'enabled', $services, function ($v, $serviceName, $service) use ($deployment) {
            return $this->validateEnabled($v);
        });
        $errored = $errored || $enabledErrored;

        // Validate the --threshold-* options.
        list($thresholdUp, $thresholdErrored) = $this->parseSetting($input, 'threshold-up', $services, function ($v, $serviceName, $service) use ($deployment) {
            return $this->validateThreshold($v, $serviceName, $service, $deployment);
        });
        $errored = $errored || $thresholdErrored;
        list($thresholdDown, $thresholdErrored) = $this->parseSetting($input, 'threshold-down', $services, function ($v, $serviceName, $service) use ($deployment) {
            return $this->validateThreshold($v, $serviceName, $service, $deployment);
        });
        $errored = $errored || $thresholdErrored;

        // Validate the --duration-* options.
        list($durationUp, $durationErrored) = $this->parseSetting($input, 'duration-up', $services, function ($v, $serviceName, $service) use ($deployment) {
            return $this->validateDuration($v);
        });
        $errored = $errored || $durationErrored;
        list($durationDown, $durationErrored) = $this->parseSetting($input, 'duration-down', $services, function ($v, $serviceName, $service) use ($deployment) {
            return $this->validateDuration($v);
        });
        $errored = $errored || $durationErrored;

        // Validate the --cooldown-* options.
        list($cooldownUp, $cooldownErrored) = $this->parseSetting($input, 'cooldown-up', $services, function ($v, $serviceName, $service) use ($deployment) {
            return $this->validateDuration($v);
        });
        $errored = $errored || $cooldownErrored;
        list($cooldownDown, $cooldownErrored) = $this->parseSetting($input, 'cooldown-down', $services, function ($v, $serviceName, $service) use ($deployment) {
            return $this->validateDuration($v);
        });
        $errored = $errored || $cooldownErrored;

        // Validate the --instances-* options.
        $instanceLimit = $defaults['instances']['max'];
        list($instancesMin, $instancesErrored) = $this->parseSetting($input, 'instances-min', $services, function ($v, $serviceName, $service) use ($deployment) {
            return $this->validateInstanceCount($v, $instanceLimit);
        });
        $errored = $errored || $instancesErrored;
        list($instancesMax, $instancesErrored) = $this->parseSetting($input, 'instances-max', $services, function ($v, $serviceName, $service) use ($deployment) {
            return $this->validateInstanceCount($v, $instanceLimit);
        });
        $errored = $errored || $instancesErrored;

        if ($errored) {
            return 1;
        }

        $this->stdErr->writeln('');

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        // Check if we should show the interactive form
        $hasAnyOptions = $input->getOption('name') !== null
            || $input->getOption('metric') !== null
            || $input->getOption('enabled') !== null
            || $input->getOption('threshold-up') !== null
            || $input->getOption('threshold-down') !== null
            || $input->getOption('duration-up') !== null
            || $input->getOption('duration-down') !== null
            || $input->getOption('cooldown-up') !== null
            || $input->getOption('cooldown-down') !== null
            || $input->getOption('instances-min') !== null
            || $input->getOption('instances-max') !== null;

        // TODO: rethink this
        $showInteractiveForm = $input->isInteractive() ; //&& !$hasAnyOptions;

        $this->stdErr->writeln('DEBUG: showInteractiveForm = ' . ($showInteractiveForm ? 'true' : 'false'));
        $this->stdErr->writeln('DEBUG: hasAnyOptions = ' . ($hasAnyOptions ? 'true' : 'false'));

        $updates = [];
        $errored = false;

        if ($showInteractiveForm) {
            // Interactive mode: let user select services and configure them
            $serviceNames = array_keys($services);

            // Ask user to select services to configure
            $text = 'Enter a number to choose a service:' . "\n" . 'Default: <question>' . $serviceNames[0] . '</question>';
            $selectedService = $questionHelper->choose($serviceNames, $text);
            $serviceName = $serviceNames[$selectedService];

            // Configure the selected service
            $service = $services[$serviceName];

            $this->stdErr->writeln('');
            $this->stdErr->writeln('<options=bold>Service: </><options=bold,underscore>' . $serviceName . '</>');
            $this->stdErr->writeln('');

            // Ask for metric name
            $choices = array_keys($defaults['triggers']);
            $default = array_key_first($choices);
            $text = 'Enter the metric name for autoscaling:' . "\n" . 'Default: <question>' . $default . '</question>';
            $choice = $questionHelper->choose($choices, $text, $default);
            $metric = $this->validateMetric($choices[$choice], $defaults['triggers']);

            // Ask for scaling up settings
            //
            // Threshold
            $default = $defaults['triggers'][$metric]['up']['threshold'];
            $updates[$serviceName]['threshold-up'] = $questionHelper->askInput('Enter the threshold for scaling up', $default, [], $this->validateThreshold);
            $this->stdErr->writeln('');

            // Duration
            $choices = array_keys(self::$validDurations);
            $defaultDuration = $defaults['triggers'][$metric]['up']['duration'];
            $default = array_search($this->formatDuration($defaults['triggers'][$metric]['up']['duration']), $choices);
            $text = 'Enter the duration for scaling up evaluation:' . "\n" . 'Default: <question>' . $default . '</question>';
            $choice = $questionHelper->choose($choices, $text, $default);
            $updates[$serviceName]['duration-up'] = $this->validateDuration($choices[$choice]);

            // Ask for scaling down settings
            //
            // Threshold
            $value = $questionHelper->askInput('Enter the threshold for scaling down', $defaults['triggers'][$metric]['down']['threshold']);
            $updates[$serviceName]['threshold-down'] = $this->validateThreshold($value);
            $this->stdErr->writeln('');

            // Duration
            $choices = array_keys(self::$validDurations);
            $default = array_search($this->formatDuration($defaults['triggers'][$metric]['down']['duration']), $choices);
            $text = 'Enter the duration for scaling down evaluation:' . "\n" . 'Default: <question>' . $default . '</question>';
            $choice = $questionHelper->choose($choices, $text, $default);
            $updates[$serviceName]['duration-down'] = $this->validateDuration($choices[$choice]);

            // Ask for enabling autoscaling based on this metric
            $value = $questionHelper->confirm(sprintf('Enable autoscaling based on <question>%s</question>?', $metric), true);
            $updates[$serviceName]['enabled'] = $this->validateEnabled($value);
            $this->stdErr->writeln('');

            // Ask for instance count limits
            $value = $questionHelper->askInput('Enter the minimum number of instances', 1);
            $updates[$serviceName]['instances-min'] = $this->validateInstanceCount($value, $instanceLimit);
            $this->stdErr->writeln('');

            $value = $questionHelper->askInput('Enter the maximum number of instances', $instanceLimit);
            $updates[$serviceName]['instances-max'] = $this->validateInstanceCount($value, $instanceLimit);
            $this->stdErr->writeln('');

            // Ask for cool down period durations
            $choices = array_keys(self::$validDurations);
            $default = array_search($this->formatDuration($defaults['scale_cooldown']['up']), $choices);
            $text = 'Enter the duration of the cool-down period for scaling up:' . "\n" . 'Default: <question>' . $default . '</question>';
            $choice = $questionHelper->choose($choices, $text, $default);
            $updates[$serviceName]['cooldown-up'] = $this->validateDuration($choices[$choice]);

            $choices = array_keys(self::$validDurations);
            $default = array_search($this->formatDuration($defaults['scale_cooldown']['down']), $choices);
            $text = 'Enter the duration of the cool-down period for scaling down:' . "\n" . 'Default: <question>' . $default . '</question>';
            $choice = $questionHelper->choose($choices, $text, $default);
            $updates[$serviceName]['cooldown-down'] = $this->validateDuration($choices[$choice]);

            if (!empty($updates[$serviceName])) {
                // since we have some changes, inject the metric name for them
                $updates[$serviceName]['metric'] = $metric;
            }
        }

        $this->stdErr->writeln('');

        if ($errored) {
            return 1;
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

        $settings = $this->makeAutoscalingSettings($updates);
        $settings = $this->api()->setAutoscalingSettings($environment, $settings);

        return 0;
    }


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
            $this->stdErr->writeln('    Duration (up): ' . $this->formatChange(
                $current['triggers'][$metric]['up'] ? $this->formatDuration($current['triggers'][$metric]['up']['duration']) : null,
                $this->formatDuration($updates['duration-up'])
            ));
        }
        if (isset($updates['duration-up'])) {
            $this->stdErr->writeln('    Threshold (down): ' . $this->formatChange(
                $current['triggers'][$metric]['down'] ? $current['triggers'][$metric]['down']['threshold'] : null,
                $updates['threshold-down']
            ));
        }
        if (isset($updates['duration-up'])) {
            $this->stdErr->writeln('    Duration (down): ' . $this->formatChange(
                $current['triggers'][$metric]['down'] ? $this->formatDuration($current['triggers'][$metric]['down']['duration']) : null,
                $this->formatDuration($updates['duration-down'])
            ));
        }

        if (isset($updates['duration-up'])) {
            $this->stdErr->writeln('    Cooldown (up): ' . $this->formatChange(
                $current['scale_cooldown'] ? $this->formatDuration($current['scale_cooldown']['up']) : null,
                $this->formatDuration($updates['cooldown-up'])
            ));
        }
        if (isset($updates['duration-up'])) {
            $this->stdErr->writeln('    Cooldown (down): ' . $this->formatChange(
                $current['scale_cooldown'] ? $this->formatDuration($current['scale_cooldown']['down']) : null,
                $this->formatDuration($updates['cooldown-down'])
            ));
        }

        if (isset($updates['duration-up'])) {
            $this->stdErr->writeln('    Instances (min): ' . $this->formatChange(
                $current['instances'] ? $current['instances']['min'] : null,
                $updates['instances-min']
            ));
        }
        if (isset($updates['duration-up'])) {
            $this->stdErr->writeln('    Instances (max): ' . $this->formatChange(
                $current['instances'] ? $current['instances']['max'] : null,
                $updates['instances-max']
            ));
        }
    }

    protected function validateService($value, $services)
    {
        if (array_key_exists($value, $services)) {
            return $value;
        }
        throw new InvalidArgumentException(sprintf('Invalid service name <error>%s</error>: must be one of: %s', $value, implode(', ', $services)));
    }

    protected function validateMetric($value, $metrics)
    {
        if (array_key_exists($value, $metrics)) {
            return $value;
        }
        throw new InvalidArgumentException(sprintf('Invalid metric name: %s. Must be one of: %s', $value, implode(', ', $metrics)));
    }

    protected function validateEnabled($value)
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Invalid value <error>%s</error>: must be true or false', $value);
        };
        return (bool)$value;
    }

    protected function validateThreshold($value)
    {
        $threshold = (float) $value;
        if ($threshold <= 0) {
            throw new InvalidArgumentException(sprintf('Invalid threshold <error>%s</error>: it must be greater than 0.', $value));
        }
        if ($threshold > 100) {
            throw new InvalidArgumentException(sprintf('Invalid threshold <error>%s</error>: it must be smaller than 100.', $value));
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

    protected function validateDuration($value)
    {
        if (!isset(self::$validDurations[$value])) {
            throw new InvalidArgumentException(sprintf('Invalid duration <error>%s</error>: must be one of %s', $value, implode(', ', array_keys(self::$validDurations))));
        }
        return self::$validDurations[$value];
    }

    protected function formatDuration($value)
    {
        $lookup = array_flip(self::$validDurations);
        if (!isset($lookup[$value])) {
            throw new InvalidArgumentException(sprintf('Invalid duration <error>%s</error>: must be one of %s', $value, implode(', ', array_keys($lookup))));
        }
        return $lookup[$value];
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
    protected function validateInstanceCount($value, $limit)
    {
        $count = (int) $value;
        if ($count != $value || $value <= 0) {
            throw new InvalidArgumentException(sprintf('Invalid instance count <error>%s</error>: it must be an integer greater than 0.', $value));
        }
        if ($limit !== null && $count > $limit) {
            throw new InvalidArgumentException(sprintf('The instance count <error>%d</error> exceeds the limit %d.', $count, $limit));
        }
        return $count;
    }

    /**
     * Parses a user's list of settings for --size, --instances or --disk.
     *
     * @param InputInterface $input
     * @param string $optionName The input option name.
     * @param array<string, Service|WebApp|Worker> $services
     * @param callable|null $validator
     *   Validate the value. The callback takes the arguments ($value,
     *   $serviceName, $service) and returns a normalized value or throws
     *   an InvalidArgumentException.
     *
     * @return array{array<string, mixed>, bool}
     *     An array of settings per service, and whether an error occurred.
     */
    private function parseSetting(InputInterface $input, $optionName, $services, $validator)
    {
        $items = ArrayArgument::getOption($input, $optionName);
        $serviceNames = array_keys($services);
        $errors = $values = [];
        foreach ($items as $item) {
            $parts = \explode(':', $item, 2);
            if (!isset($parts[1]) || $parts[0] === '') {
                $errors[] = sprintf('<error>%s</error> is not valid; it must be in the format "name:value".', $item);
                continue;
            }
            list($pattern, $value) = $parts;
            $givenServiceNames = Wildcard::select($serviceNames, [$pattern]);
            if (empty($givenServiceNames)) {
                $errors[] = sprintf('App/worker <error>%s</error> not found.', $pattern);
                continue;
            }
            foreach ($givenServiceNames as $name) {
                try {
                    $normalized = $validator($value, $name, $services[$name]);
                } catch (\InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                    continue;
                }
                if (isset($values[$name]) && $values[$name] !== $normalized) {
                    $this->debug(sprintf('Overriding value %s with %s for %s in --%s', $values[$name], $normalized, $name, $optionName));
                }
                $values[$name] = $normalized;
            }
        }
        $errored = count($errors) > 0;
        if ($errored) {
            $this->stdErr->writeln($this->formatErrors($errors, $optionName));
        }
        return [$values, $errored];
    }

    /**
     * Print errors found after parsing a setting.
     *
     * @param array $errors
     * @param string $optionName
     *
     * @return string[]
     */
    private function formatErrors(array $errors, $optionName)
    {
        if (!$errors) {
            return [];
        }
        $ret = [];
        if (count($errors) === 1) {
            $ret[] = sprintf('Error in --%s value:', $optionName);
            $ret[] = '  ' . reset($errors);
        } else {
            $ret[] = sprintf('Errors in --%s values:', $optionName);
            foreach ($errors as $error) {
                $ret[] = '  * ' . $error;
            }
        }
        return $ret;
    }

    protected function formatBoolean($value)
    {
        return $value ? "true" : "false";
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
    protected function formatChange($previousValue, $newValue, $suffix = '')
    {
        if ($previousValue === null || $newValue === $previousValue) {
            return sprintf('<info>%s%s</info>', $newValue, $suffix);
        }
        if ($newValue === "true" || $newValue === "false") {
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
}

