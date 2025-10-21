<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Resources;

use Platformsh\Cli\Service\ResourcesUtil;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'resources:get', description: 'View the resources of apps and services on an environment', aliases: ['resources', 'res'])]
class ResourcesGetCommand extends ResourcesCommandBase
{
    /** @var array<string, string> */
    protected array $tableHeader = [
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

    /** @var string[] */
    protected array $defaultColumns = ['service', 'profile_size', 'cpu_type', 'cpu', 'memory', 'disk', 'instance_count'];

    public function __construct(private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly ResourcesUtil $resourcesUtil, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('service', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by service name. This can select any service, including apps and workers.')
            ->addOption('app', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by app name')
            ->addOption('worker', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by worker name')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by service, app or worker type, e.g. "postgresql"')
            ->addOption('cpu-type', null, InputOption::VALUE_OPTIONAL, 'Filter by CPU type, e.g "guaranteed"');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        if ($this->config->has('service.resources_help_url')) {
            $this->setHelp('For more information on managing resources, see: <info>' . $this->config->getStr('service.resources_help_url') . '</info>');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        if (!$this->api->supportsSizingApi($selection->getProject())) {
            $this->stdErr->writeln(sprintf('The flexible resources API is not enabled for the project %s.', $this->api->getProjectLabel($selection->getProject(), 'comment')));
            return 1;
        }

        $environment = $selection->getEnvironment();

        try {
            $nextDeployment = $this->resourcesUtil->loadNextDeployment($environment);
        } catch (EnvironmentStateException $e) {
            if ($environment->status === 'inactive') {
                $this->stdErr->writeln(sprintf('The environment %s is not active so resource configuration cannot be read.', $this->api->getEnvironmentLabel($environment, 'comment')));
                return 1;
            }
            throw $e;
        }

        $services = $this->resourcesUtil->allServices($nextDeployment);
        if (empty($services)) {
            $this->stdErr->writeln('No apps or services found');
            return 1;
        }

        $services = $this->resourcesUtil->filterServices($services, $input);
        if (empty($services)) {
            return 1;
        }

        $autoscalingEnabled = [];
        // Check autoscaling settings for the environment, as autoscaling prevents changing some resources manually.
        $autoscalingSettings = $this->api->getAutoscalingSettings($environment);
        if ($autoscalingSettings) {
            foreach ($autoscalingSettings->getData()['services'] as $service => $serviceSettings) {
                $autoscalingEnabled[$service] = !empty($serviceSettings['enabled']);
            }
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf('Resource configuration for the project %s, environment %s:', $this->api->getProjectLabel($selection->getProject()), $this->api->getEnvironmentLabel($environment)));
        }

        $empty = $this->table->formatIsMachineReadable() ? '' : '<comment>not set</comment>';
        $notApplicable = $this->table->formatIsMachineReadable() ? '' : 'N/A';

        $containerProfiles = $this->sortContainerProfiles($nextDeployment->container_profiles);

        $rows = [];
        $cpuTypeOption = $input->getOption('cpu-type');
        $autoscalingIndicator = '<comment>(A)</comment>';
        $hasAutoscalingIndicator = false;
        foreach ($services as $name => $service) {
            $properties = $service->getProperties();
            if (!$this->table->formatIsMachineReadable() && !empty($autoscalingEnabled[$name])) {
                $name .= ' ' . $autoscalingIndicator;
                $hasAutoscalingIndicator = true;
            }
            $row = [
                'service' => $name,
                'type' => $this->propertyFormatter->format($service->type, 'service_type'),
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
                if ($cpuTypeOption != "" && isset($profileInfo['cpu_type']) && $profileInfo['cpu_type'] != $cpuTypeOption) {
                    continue;
                }

                $row['cpu_type'] = $profileInfo['cpu_type'] ?? '';
                $row['cpu'] = isset($profileInfo['cpu']) ? $this->resourcesUtil->formatCPU($profileInfo['cpu']) : '';
                $row['memory'] = isset($profileInfo['cpu']) ? $profileInfo['memory'] : '';
            }

            if (!empty($properties['resources'])) {
                foreach ($properties['resources'] as $key => $value) {
                    if (isset($row[$key])) {
                        $row[$key] = $value === null ? '' : $value;
                    }
                }
            }

            if (!$this->resourcesUtil->supportsDisk($service)) {
                $row['disk'] = $notApplicable;
            } elseif (array_key_exists('disk', $properties)) {
                if (empty($properties['disk'])) {
                    $row['disk'] = empty($properties['resources']) ? $empty : '';
                } else {
                    $row['disk'] = $this->propertyFormatter->format($properties['disk'], 'disk');
                }
            }

            $row['instance_count'] = isset($properties['instance_count']) ? $this->propertyFormatter->format($properties['instance_count'], 'instance_count') : '1';

            $rows[] = $row;
        }

        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            if ($hasAutoscalingIndicator) {
                $this->stdErr->writeln($autoscalingIndicator . ' - Indicates that the service has autoscaling enabled');
            }

            $executable = $this->config->getStr('application.executable');

            $isOriginalCommand = $input instanceof ArgvInput;
            if ($isOriginalCommand) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf('Configure resources by running: <info>%s resources:set</info>', $executable));
            }
        }

        return 0;
    }
}
