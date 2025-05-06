<?php

namespace Platformsh\Cli\Command\Resources;

use Platformsh\Cli\Service\Table;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ResourcesGetCommand extends ResourcesCommandBase
{
    protected $tableHeader = [
        'service' => 'App or service',
        'type' => 'Type',
        'profile' => 'Profile',
        'profile_size' => 'Size',
        'cpu_type' => 'CPU type',
        'cpu' => 'CPU',
        'memory' => 'Memory (MB)',
        'disk' => 'Disk (MB)',
        'instance_count' => 'Instances',
        'base_memory' => 'Base memory',
        'memory_ratio' => 'Memory ratio',
    ];
    protected $defaultColumns = ['service', 'profile_size', 'cpu_type', 'cpu', 'memory', 'disk', 'instance_count'];

    protected function configure()
    {
        $this->setName('resources:get')
            ->setAliases(['resources', 'res'])
            ->setDescription('View the resources of apps and services on an environment')
            ->addOption('service', 's', InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Filter by service name. This can select any service, including apps and workers.')
            ->addOption('app', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Filter by app name')
            ->addOption('worker', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Filter by worker name')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by service, app or worker type, e.g. "postgresql"')
            ->addOption('cpu-type', null, InputOption::VALUE_OPTIONAL, 'Filter by CPU type, e.g "guaranteed"');
        $this->addProjectOption()->addEnvironmentOption();
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        if ($this->config()->has('service.resources_help_url')) {
            $this->setHelp('For more information on managing resources, see: <info>' . $this->config()->get('service.resources_help_url') . '</info>');
        }
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
            $nextDeployment = $this->loadNextDeployment($environment);
        } catch (EnvironmentStateException $e) {
            if ($environment->status === 'inactive') {
                $this->stdErr->writeln(sprintf('The environment %s is not active so resource configuration cannot be read.', $this->api()->getEnvironmentLabel($environment, 'comment')));
                return 1;
            }
            throw $e;
        }

        $services = $this->allServices($nextDeployment);
        if (empty($services)) {
            $this->stdErr->writeln('No apps or services found');
            return 1;
        }

        $services = $this->filterServices($services, $input);
        if (empty($services)) {
            return 1;
        }

        /** @var Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf('Resource configuration for the project %s, environment %s:', $this->api()->getProjectLabel($this->getSelectedProject()), $this->api()->getEnvironmentLabel($environment)));
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $empty = $table->formatIsMachineReadable() ? '' : '<comment>not set</comment>';
        $notApplicable = $table->formatIsMachineReadable() ? '' : 'N/A';

        $containerProfiles = $nextDeployment->container_profiles;

        $rows = [];
        $cpuTypeOption = $input->getOption('cpu-type');
        foreach ($services as $name => $service) {
            $properties = $service->getProperties();
            $row = [
                'service' => $name,
                'type' => $formatter->format($service->type, 'service_type'),
                'profile' => $properties['container_profile'] ?: $empty,
                'profile_size' => $empty,
                'base_memory' => $empty,
                'memory_ratio' => $empty,
                'disk' => $empty,
                'instance_count' => $empty,
                'cpu_type' => $empty,
                'cpu' => $empty,
                'memory' => $empty,
            ];

            if (isset($properties['container_profile']) && isset($containerProfiles[$properties['container_profile']][$properties['resources']['profile_size']])) {
                $profileInfo = $containerProfiles[$properties['container_profile']][$properties['resources']['profile_size']];
                if ($cpuTypeOption != "" && isset($profileInfo['type']) && $profileInfo['type'] != $cpuTypeOption) {
                    continue;
                }

                $row['cpu_type'] = isset($profileInfo['type']) ? $profileInfo['type'] : '';
                $row['cpu'] = isset($profileInfo['cpu']) ? $this->formatCPU($profileInfo['cpu']) : '';
                $row['memory'] = isset($profileInfo['cpu']) ? $profileInfo['memory'] : '';
            }


            if (!empty($properties['resources'])) {
                foreach ($properties['resources'] as $key => $value) {
                    if (isset($row[$key])) {
                        $row[$key] = $value === null ? '' : $value;
                    }
                }
            }

            if (!$this->supportsDisk($service)) {
                $row['disk'] = $notApplicable;
            } elseif (array_key_exists('disk', $properties)) {
                if (empty($properties['disk'])) {
                    $row['disk'] = empty($properties['resources']) ? $empty : '';
                } else {
                    $row['disk'] = $formatter->format($properties['disk'], 'disk');
                }
            }

            $row['instance_count'] = isset($properties['instance_count']) ? $formatter->format($properties['instance_count'], 'instance_count') : '1';

            $rows[] = $row;
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $executable = $this->config()->get('application.executable');
            $isOriginalCommand = $input instanceof ArgvInput;
            if ($isOriginalCommand) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf('Configure resources by running: <info>%s resources:set</info>', $executable));
            }
        }

        return 0;
    }
}
