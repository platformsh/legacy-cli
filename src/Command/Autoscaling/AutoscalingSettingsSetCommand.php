<?php

namespace Platformsh\Cli\Command\Autoscaling;

use Symfony\Component\Console\Attribute\AsCommand;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\ResourcesUtil;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\Deployment\Service;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Deployment\Worker;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'autoscaling:set', description: 'Set the autoscaling configuration of apps or workers in an environment')]
class AutoscalingSettingsSetCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly ResourcesUtil $resourcesUtil, private readonly Selector $selector, private readonly SubCommandRunner $subCommandRunner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Name of the app or worker to configure autoscaling for')
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes that would be made, without changing anything');

        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());

        $helpLines = [
            'Configure automatic scaling for apps or workers in an environment.',
            '',
            sprintf('You can also configure resources statically by running: <info>%s resources:set</info>', $this->config->getStr('application.executable')),
        ];
        if ($this->config->has('service.autoscaling_help_url')) {
            $helpLines[] = '';
            $helpLines[] = 'For more information on autoscaling, see: <info>' . $this->config->getStr('service.autoscaling_help_url') . '</info>';
        }
        $this->setHelp(implode("\n", $helpLines));

        $this->addExample('Enable autoscaling for an application using the default configuration', '--service app --metric cpu');
        $this->addExample('Enable autoscaling for an application specifying a minimum of instances at all times', '--service app --metric cpu --instances-min 3');
        $this->addExample('Enable autoscaling for an application specifying a maximum of instances at most', '--service app --metric cpu --instances-max 5');
        $this->addExample('Disable autoscaling on cpu for an application', '--service app --metric cpu --enabled false');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        if (!$this->api->supportsAutoscaling($selection->getProject())) {
            $this->stdErr->writeln(sprintf('The autoscaling API is not enabled for the project %s.', $this->api->getProjectLabel($selection->getProject(), 'comment')));
            return 1;
        }

        $environment = $selection->getEnvironment();

        try {
            $deployment = $this->api->getCurrentDeployment($environment);
        } catch (EnvironmentStateException $e) {
            if ($environment->status === 'inactive') {
                $this->stdErr->writeln(sprintf('The environment %s is not active so autoscaling configuration cannot be set.', $this->api->getEnvironmentLabel($environment, 'comment')));
                return 1;
            }
            throw $e;
        }

        if (!$this->api->getAutoscalingSettingsLink($environment, true)) {
            throw new EnvironmentStateException('Managing autoscaling settings is not currently available', $environment);
        }

        $autoscalingSettings = $this->api->getAutoscalingSettings($environment);
        if (!$autoscalingSettings) {
            throw new EnvironmentStateException('Managing autoscaling settings is not currently available', $environment);
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

        $supportedMetrics = $this->getSupportedMetrics($defaults);

        // Validate the --metric option.
        $metric = $input->getOption('metric');
        if ($metric !== null) {
            $metric = $this->validateMetric($metric, $supportedMetrics);
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

        // Show current autoscaling settings
        if (($exitCode = $this->subCommandRunner->run('autoscaling:get', [
            '--project' => $environment->project,
            '--environment' => $environment->id,
        ], $this->stdErr)) !== 0) {
            return $exitCode;
        }

        $this->stdErr->writeln('');

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
                $serviceNamesIndexed = array_combine($serviceNames, $serviceNames);
                $selectedService = $this->questionHelper->choose($serviceNamesIndexed, $text, $default);
                $service = $selectedService;
            }

            // Get autoscaling current values for selected service
            $currentServiceSettings = $autoscalingSettings['services'][$service];

            $this->stdErr->writeln('<options=bold>' . ucfirst($this->typeName($services[$service])) . ': </><options=bold,underscore>' . $service . '</>');
            $this->stdErr->writeln('');

            if ($metric === null) {
                // Ask for metric name
                $choices = $supportedMetrics;
                $default = $choices[0];
                $text = 'Which metric should be configured as a trigger for autoscaling?' . "\n" . 'Default: <question>' . $default . '</question>';
                $choicesIndexed = array_combine($choices, $choices);
                $metric = $this->questionHelper->choose($choicesIndexed, $text, $default, false);
            }

            $this->handleScalingSettings('up', $service, $metric, $currentServiceSettings, $defaults, $thresholdUp, $durationUp, $cooldownUp, $updates);
            $this->handleScalingSettings('down', $service, $metric, $currentServiceSettings, $defaults, $thresholdDown, $durationDown, $cooldownDown, $updates);
            $this->handleInstanceSettings($service, $currentServiceSettings, $instanceLimit, $instancesMin, $instancesMax, $updates);

            // Assume autoscaling should be enabled when showing interactive form
            if ($enabled === null) {
                $enabled = true;
            }
            // Only mark 'enabled' as an updated field if it is changing
            if ($currentServiceSettings['enabled'] !== $enabled) {
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

            if ($cooldownUp !== null) {
                $updates[$service]['cooldown-up'] = $cooldownUp;
            }

            if ($thresholdDown !== null) {
                $updates[$service]['threshold-down'] = $thresholdDown;
            }

            if ($durationDown !== null) {
                $updates[$service]['duration-down'] = $durationDown;
            }

            if ($cooldownDown !== null) {
                $updates[$service]['cooldown-down'] = $cooldownDown;
            }

            if ($instancesMin !== null) {
                $updates[$service]['instances-min'] = $instancesMin;
            }

            if ($instancesMax !== null) {
                $updates[$service]['instances-max'] = $instancesMax;
            }

            // Only mark 'enabled' as an updated field if it was explicitly set and is changing
            $currentServiceSettings = $autoscalingSettings['services'][$service];
            if ($enabled !== null && $currentServiceSettings['enabled'] !== $enabled) {
                $updates[$service]['enabled'] = $enabled;
            }

            if (!empty($updates[$service])) {
                $metric = $this->validateMetric($metric, $supportedMetrics);
                // since we have some changes, inject the metric name for them
                $updates[$service]['metric'] = $metric;
            }

        }

        if (empty($updates)) {
            $this->stdErr->writeln('No autoscaling changes were provided: nothing to update');
            return 0;
        }

        $this->summarizeChanges($updates, $autoscalingSettings['services']);

        $this->io->debug('Raw updates: ' . json_encode($updates, JSON_UNESCAPED_SLASHES));

        if ($input->getOption('dry-run')) {
            return 0;
        }

        $this->stdErr->writeln('');

        $questionText = 'Are you sure you want to continue?';
        if (!$this->questionHelper->confirm($questionText)) {
            return 1;
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Setting the autoscaling configuration on the environment ' . $this->api->getEnvironmentLabel($environment));

        $data = $this->makeAutoscalingSettingsData($updates);
        $this->api->setAutoscalingSettings($environment, $data);

        return 0;
    }

    /**
     * Handle scaling settings (up/down) for interactive mode.
     *
     * @param array<string, mixed>|null $currentServiceSettings
     * @param array<string, mixed> $defaults
     * @param array<string, array<string, mixed>> $updates
     */
    private function handleScalingSettings(
        string $direction,
        string $service,
        string $metric,
        ?array $currentServiceSettings,
        array  $defaults,
        ?float &$threshold,
        ?int   &$duration,
        ?int   &$cooldown,
        array  &$updates,
    ): void {
        if ($threshold === null || $duration === null || $cooldown === null) {
            $text = '<options=underscore>Settings for scaling <options=bold,underscore>' . $direction . '</></>';
            $this->stdErr->writeln($text);
            $this->stdErr->writeln('');

            $threshold = $this->askForSetting(
                $threshold,
                'Enter the threshold (%)',
                $currentServiceSettings['triggers'][$metric][$direction]['threshold'] ?? null,
                $defaults['triggers'][$metric][$direction]['threshold'],
                function ($value) { return $this->validateThreshold($value); },
                $service,
                'threshold-' . $direction,
                $updates
            );

            $duration = $this->askForDurationSetting(
                $duration,
                'Enter the duration of the evaluation period',
                $currentServiceSettings['triggers'][$metric][$direction]['duration'] ?? null,
                $defaults['triggers'][$metric][$direction]['duration'],
                $service,
                'duration-' . $direction,
                $updates
            );

            $cooldown = $this->askForDurationSetting(
                $cooldown,
                'Enter the duration of the cool-down period',
                $currentServiceSettings['scale_cooldown'][$direction] ?? null,
                $defaults['scale_cooldown'][$direction],
                $service,
                'cooldown-' . $direction,
                $updates
            );
        }
    }

    /**
     * Handle instance settings for interactive mode.
     *
     * @param array<string, mixed>|null $currentServiceSettings
     * @param array<string, array<string, mixed>> $updates
     */
    private function handleInstanceSettings(
        string $service,
        ?array $currentServiceSettings,
        int $instanceLimit,
        ?int &$instancesMin,
        ?int &$instancesMax,
        array &$updates
    ): void {
        $instancesMin = $this->askForSetting(
            $instancesMin,
            'Enter the minimum number of instances',
            $currentServiceSettings['instances']['min'] ?? null,
            1,
            function ($value) use ($instanceLimit) { return $this->validateInstanceCount($value, $instanceLimit); },
            $service,
            'instances-min',
            $updates
        );

        $instancesMax = $this->askForSetting(
            $instancesMax,
            'Enter the maximum number of instances',
            $currentServiceSettings['instances']['max'] ?? null,
            $instanceLimit,
            function ($value) use ($instanceLimit) { return $this->validateInstanceCount($value, $instanceLimit); },
            $service,
            'instances-max',
            $updates
        );
    }

    /**
     * Generic method to ask for a setting value.
     *
     * @param mixed $currentValue Current value (null if not set)
     * @param string $prompt Prompt text for user input
     * @param mixed $existingValue Existing value from current settings
     * @param mixed $defaultValue Default value to use if no existing value
     * @param callable $validator Function to validate user input
     * @param string $service Service name
     * @param string $updateKey Key to use in updates array
     * @param array<string, array<string, mixed>> $updates Updates array (passed by reference)
     *
     * @return mixed The validated value
     */
    private function askForSetting(
        mixed $currentValue,
        string $prompt,
        mixed $existingValue,
        mixed $defaultValue,
        callable $validator,
        string $service,
        string $updateKey,
        array &$updates
    ): mixed {
        if ($currentValue === null) {
            $default = $existingValue ?? $defaultValue;
            $newValue = $this->questionHelper->askInput($prompt, $default, [], $validator);
            $this->stdErr->writeln('');
            if ($newValue !== $existingValue) {
                $updates[$service][$updateKey] = $newValue;
            }
            return $newValue;
        } else {
            $updates[$service][$updateKey] = $currentValue;
            return $currentValue;
        }
    }

    /**
     * Specialized method to ask for duration settings with choices.
     *
     * @param int|null $currentValue Current duration value (null if not set)
     * @param string $prompt Prompt text for user input
     * @param int|null $existingValue Existing duration from current settings
     * @param int $defaultValue Default duration value
     * @param string $service Service name
     * @param string $updateKey Key to use in updates array
     * @param array<string, array<string, mixed>> $updates Updates array (passed by reference)
     *
     * @return int The validated duration value
     */
    private function askForDurationSetting(
        ?int $currentValue,
        string $prompt,
        ?int $existingValue,
        int $defaultValue,
        string $service,
        string $updateKey,
        array &$updates
    ): int {
        if ($currentValue === null) {
            $choices = array_keys(self::$validDurations);
            $default = $existingValue ?? $defaultValue;
            $newValue = $this->questionHelper->askInput($prompt, $this->formatDuration($default), $choices, function ($v) {
                return $this->validateDuration($v);
            });
            $this->stdErr->writeln('');

            if ($newValue !== $existingValue) {
                $updates[$service][$updateKey] = $newValue;
            }
            return $newValue;
        } else {
            $updates[$service][$updateKey] = $currentValue;
            return $currentValue;
        }
    }

    /**
     * Build an AutoscalingSettings instance.
     *
     * @param array<string, array<string, mixed>> $updates
     * @return array<string, mixed>
     */
    protected function makeAutoscalingSettingsData(array $updates): array
    {
        $data = ['services' => []];

        foreach ($updates as $service => $serviceSettings) {
            $serviceData = [];
            if (isset($serviceSettings['metric'])) {
                $triggerData = [];
                if (isset($serviceSettings['threshold-up'])) {
                    $triggerData['up'] = ['threshold' => $serviceSettings['threshold-up']];
                }
                if (isset($serviceSettings['duration-up'])) {
                    if (isset($triggerData['up'])) {
                        $triggerData['up']['duration'] = $serviceSettings['duration-up'];
                    } else {
                        $triggerData['up'] = ['duration' => $serviceSettings['duration-up']];
                    }
                }
                if (isset($serviceSettings['threshold-down'])) {
                    $triggerData['down'] = ['threshold' => $serviceSettings['threshold-down']];
                }
                if (isset($serviceSettings['duration-down'])) {
                    if (isset($triggerData['down'])) {
                        $triggerData['down']['duration'] = $serviceSettings['duration-down'];
                    } else {
                        $triggerData['down'] = ['duration' => $serviceSettings['duration-down']];
                    }
                }
                if (isset($serviceSettings['enabled'])) {
                    $triggerData['enabled'] = $serviceSettings['enabled'];
                }
                $serviceData['triggers'] = [$serviceSettings['metric'] => $triggerData];
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
     * @param array<string, array<string, mixed>> $updates
     * @param array<string, array<string, mixed>> $settings
     */
    private function summarizeChanges(array $updates, array $settings): void
    {
        $this->stdErr->writeln('<options=bold>Summary of changes:</>');
        foreach ($updates as $service => $serviceUpdates) {
            $this->summarizeChangesPerService($service, $settings[$service] ?? null, $serviceUpdates);
        }
    }

    /**
     * Summarizes changes per service.
     *
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $updates
     */
    private function summarizeChangesPerService(string $name, ?array $current, array $updates): void
    {
        $this->stdErr->writeln(sprintf('  <options=bold>Service: </><info>%s</info>', $name));

        $metric = $updates['metric'];
        $this->stdErr->writeln(sprintf('  Metric: <info>%s</info>', $metric));

        $action = 'remain';
        $enabledText = $current['triggers'][$metric]['enabled'] ? 'enabled' : 'disabled';
        if (isset($updates['enabled'])) {
            if ($current['triggers'][$metric]['enabled'] != $updates['enabled']) {
                $action = 'become';
                $enabledText = $updates['enabled'] ? 'enabled' : 'disabled';
            }
        }
        $color = $enabledText == 'enabled' ? 'green' : 'yellow';
        $status = '<fg=' . $color . '>' . $enabledText . '</>';
        $this->stdErr->writeln('    Autoscaling will ' . $action . ': ' . $status);

        if (isset($updates['threshold-up']) || isset($updates['duration-up']) || isset($updates['cooldown-up'])) {
            $this->stdErr->writeln('    Scaling <options=bold>up</>');

            if (isset($updates['threshold-up'])) {
                $this->stdErr->writeln('      Threshold: ' . $this->resourcesUtil->formatChange(
                    isset($current['triggers'][$metric]['up']) ? $current['triggers'][$metric]['up']['threshold'] : null,
                    $updates['threshold-up'],
                    '%'
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

            if (isset($updates['threshold-down'])) {
                $this->stdErr->writeln('      Threshold: ' . $this->resourcesUtil->formatChange(
                    isset($current['triggers'][$metric]['down']) ? $current['triggers'][$metric]['down']['threshold'] : null,
                    $updates['threshold-down'],
                    '%'
                ));
            }
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
                $this->stdErr->writeln('      Min: ' . $this->resourcesUtil->formatChange(
                    isset($current['instances']) ? $current['instances']['min'] : null,
                    $updates['instances-min']
                ));
            }

            if (isset($updates['instances-max'])) {
                $this->stdErr->writeln('      Max: ' . $this->resourcesUtil->formatChange(
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
     * @param array<string, WebApp|Worker|Service> $services
     *
     * @throws InvalidArgumentException
     *
     * @return string
     */
    protected function validateService(string $value, array $services): string
    {
        if (array_key_exists($value, $services)) {
            return $value;
        }
        $serviceNames = array_keys($services);
        throw new InvalidArgumentException(sprintf('Invalid service name <error>%s</error>. Available services: %s', $value, implode(', ', $serviceNames)));
    }

    /**
     * Return the names of supported metrics.
     *
     * @param array<string, mixed> $defaults Autoscaling settings defaults
     *
     * @return array<int, string> Supported metric names
     */
    protected function getSupportedMetrics(array $defaults): array
    {
        // TODO: change this once we properly support multiple metrics other than 'cpu' or 'memory'
        // override supported metrics to only support cpu/memory despite what the backend allows
        return ['cpu', 'memory'];
        //return array_keys($defaults['triggers']);
    }

    /**
     * Validates a metric name.
     *
     * @param string $value   Name of metric to validate
     * @param array<int, string>  $metrics List of valid metric names
     *
     * @throws InvalidArgumentException
     *
     * @return string
     */
    protected function validateMetric(string $value, array $metrics): string
    {
        if (in_array($value, $metrics, true)) {
            return $value;
        }
        throw new InvalidArgumentException(sprintf('Invalid metric name <error>%s</error>. Available metrics: %s', $value, implode(', ', $metrics)));
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
    protected function validateBoolean(float|int|string|bool $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match ($value) {
            "true", "yes" => true,
            "false", "no" => false,
            default => throw new InvalidArgumentException(sprintf('Invalid value <error>%s</error>: must be one of: true, yes, false, no', $value)),
        };
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
    protected function validateThreshold(float|int $value, string $context = ''): float
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

    /**
     * @var array<string, int>
     */
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
     * @param string $context
     *
     * @throws InvalidArgumentException
     *
     * @return int
     */
    protected function validateDuration(string $value, string $context = ''): int
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
    protected function typeName(WebApp|Worker|Service $service): string
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
     * @throws InvalidArgumentException
     */
    protected function validateInstanceCount(string $value, ?int $limit, string $context = ''): int
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
     * Formats a duration.
     */
    protected function formatDuration(int $value): string
    {
        $lookup = array_flip(self::$validDurations);
        if (!isset($lookup[$value])) {
            throw new InvalidArgumentException(sprintf('Invalid duration <error>%s</error>: must be one of %s', $value, implode(', ', array_keys($lookup))));
        }
        return $lookup[$value];
    }

    /**
     * Formats a change in a duration.
     *
     * @param int|string $previousValue
     * @param int|string $newValue
     *
     * @return string
     */
    protected function formatDurationChange(int|string $previousValue, int|string $newValue): string
    {
        return $this->resourcesUtil->formatChange(
            $previousValue,
            $newValue,
            '',
            function ($previousValue, $newValue) {
                if (!isset(self::$validDurations[$previousValue]) || !isset(self::$validDurations[$newValue])) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid duration key(s): previousValue=<error>%s</error>, newValue=<error>%s</error>. Valid keys are: %s',
                        $previousValue,
                        $newValue,
                        implode(', ', array_keys(self::$validDurations))
                    ));
                }
                return self::$validDurations[$previousValue] < self::$validDurations[$newValue];
            }
        );
    }
}
