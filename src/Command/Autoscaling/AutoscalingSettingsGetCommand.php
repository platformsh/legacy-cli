<?php

namespace Platformsh\Cli\Command\Autoscaling;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AutoscalingSettingsGetCommand extends CommandBase
{
    protected $tableHeader = [
        'service' => 'Service',
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
    protected $defaultColumns = ['service', 'metric', 'direction', 'threshold', 'duration', 'enabled', 'instance_count'];

    protected function configure()
    {
        $this->setName('autoscaling:get')
            ->setAliases(['autoscaling'])
            ->setDescription('View the autoscaling configuration of apps and services on an environment')
            ->addOption('app', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Filter by app name')
            ->addOption('worker', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Filter by worker name')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by app or worker type, e.g. "php"');
        $this->addProjectOption()->addEnvironmentOption();
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        if (!$this->api()->supportsSizingApi($this->getSelectedProject())) {
            $this->stdErr->writeln(sprintf('The flexible resources API is not enabled for the project %s.', $this->api()->getProjectLabel($this->getSelectedProject(), 'comment')));
            return 1;
        }

        $environment = $this->getSelectedEnvironment();

        try {
            $deployment = $this->api()->getCurrentDeployment($environment);
        } catch (EnvironmentStateException $e) {
            if ($environment->status === 'inactive') {
                $this->stdErr->writeln(sprintf('The environment %s is not active so autoscaling configuration cannot be read.', $this->api()->getEnvironmentLabel($environment, 'comment')));
                return 1;
            }
            throw $e;
        }

        try {
            $autoscalingSettings = $this->api()->getAutoscalingSettings($environment)->getData();
        } catch (EnvironmentStateException $e) {
            throw $e;
        }

        $services = array_merge($deployment->webapps, $deployment->workers);
        if (empty($services)) {
            $this->stdErr->writeln('No services found');
            return 1;
        }

        /** @var Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf('Autoscaling configuration for the project %s, environment %s:', $this->api()->getProjectLabel($this->getSelectedProject()), $this->api()->getEnvironmentLabel($environment)));
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $empty = $table->formatIsMachineReadable() ? '' : '<comment>not set</comment>';
        $notApplicable = $table->formatIsMachineReadable() ? '' : 'N/A';

        $rows = [];
        foreach ($autoscalingSettings['services'] as $name => $settings) {
            $row = [
                'service' => $name,
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
                    if ($direction == "enabled") {
                        $row['enabled'] = $condition;
                        continue;
                    }
                    $row['direction'] = $direction;
                    $row['threshold'] = $condition['threshold'];
                    $row['duration'] = $condition['duration'];

                    $row['cooldown'] = $settings['scale_cooldown'][$direction];
                    $row['min_instances'] = $settings['instances']['min'];
                    $row['max_instances'] = $settings['instances']['max'];

                    $properties = $services[$name]->getProperties();
                    $row['instance_count'] = isset($properties['instance_count']) ? $formatter->format($properties['instance_count'], 'instance_count') : '1';


                    $rows[] = $row;
                    continue;
                }
            }
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        return 0;
    }
}

