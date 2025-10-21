<?php

namespace Platformsh\Cli\Command\Autoscaling;

use Symfony\Component\Console\Attribute\AsCommand;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'autoscaling:get', description: 'View the autoscaling configuration of apps and workers on an environment', aliases: ['autoscaling'])]
class AutoscalingSettingsGetCommand extends CommandBase
{
    /** @var array<string, string> */
    protected array $tableHeader = [
        'service' => 'App or service',
        'metric' => 'Metric',
        'direction' => 'Direction',
        'threshold' => 'Threshold (%)',
        'duration' => 'Duration (s)',
        'cooldown' => 'Cooldown (s)',
        'enabled' => 'Enabled',
        'instance_count' => 'Instances',
        'min_instances' => 'Minimum instances',
        'max_instances' => 'Maximum instances',
    ];

    /** @var string[] */
    protected array $defaultColumns = ['service', 'metric', 'direction', 'threshold', 'duration', 'enabled', 'instance_count'];

    public function __construct(private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
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
                $this->stdErr->writeln(sprintf('The environment %s is not active so autoscaling configuration cannot be read.', $this->api->getEnvironmentLabel($environment, 'comment')));
                return 1;
            }
            throw $e;
        }

        $autoscalingSettings = $this->api->getAutoscalingSettings($environment);
        if (!$autoscalingSettings) {
            $this->stdErr->writeln(\sprintf('Autoscaling support is not currently available on the environment: %s', $this->api->getEnvironmentLabel($environment, 'error')));
            return 1;
        }
        $autoscalingSettings = $autoscalingSettings->getData();

        $services = array_merge($deployment->webapps, $deployment->workers);
        if (empty($services)) {
            $this->stdErr->writeln('No apps or workers found.');
            return 1;
        }

        if (!empty($autoscalingSettings['services'])) {
            if (!$this->table->formatIsMachineReadable()) {
                $this->stdErr->writeln(sprintf('Autoscaling configuration for the project %s, environment %s:', $this->api->getProjectLabel($selection->getProject()), $this->api->getEnvironmentLabel($environment)));
            }

            $empty = $this->table->formatIsMachineReadable() ? '' : '<comment>not set</comment>';

            $rows = [];
            foreach ($autoscalingSettings['services'] as $service => $settings) {
                $row = [
                    'service' => $service,
                    'metric' => $empty,
                    'direction' => $empty,
                    'threshold' => $empty,
                    'duration' => $empty,
                    'enabled' => $empty,
                    'cooldown' => $empty,
                    'min_instances' => $empty,
                    'max_instances' => $empty,
                    'instance_count' => $empty,
                ];

                foreach ($settings['triggers'] as $metric => $conditions) {
                    $row['metric'] = $metric;
                    foreach ($conditions as $direction => $condition) {
                        if ($direction === 'enabled') {
                            $row['enabled'] = $this->propertyFormatter->format($condition, 'enabled');
                            continue;
                        }
                        $row['direction'] = $direction;
                        $row['threshold'] = sprintf('%.1f%%', $condition['threshold']);
                        $row['duration'] = $condition['duration'];

                        $row['cooldown'] = $settings['scale_cooldown'][$direction];
                        $row['min_instances'] = $settings['instances']['min'];
                        $row['max_instances'] = $settings['instances']['max'];

                        $properties = $services[$service]->getProperties();
                        $row['instance_count'] = isset($properties['instance_count']) ? $this->propertyFormatter->format($properties['instance_count'], 'instance_count') : '1';


                        $rows[] = $row;
                    }
                }
            }

            $this->table->render($rows, $this->tableHeader, $this->defaultColumns);
        } else {
            $this->stdErr->writeln(sprintf('No autoscaling configuration found for the project %s, environment %s.', $this->api->getProjectLabel($selection->getProject()), $this->api->getEnvironmentLabel($environment)));
            $isOriginalCommand = $input instanceof ArgvInput;
            if ($isOriginalCommand) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf('You can configure autoscaling by running: <info>%s autoscaling:set</info>', $this->config->getStr('application.executable')));
            }
        }

        return 0;
    }
}
