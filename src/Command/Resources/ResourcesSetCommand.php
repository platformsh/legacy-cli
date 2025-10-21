<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Resources;

use Platformsh\Cli\Service\ResourcesUtil;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Platformsh\Client\Model\Deployment\Service;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Deployment\Worker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'resources:set', description: 'Set the resources of apps and services on an environment')]
class ResourcesSetCommand extends ResourcesCommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly ResourcesUtil $resourcesUtil, private readonly Selector $selector, private readonly SubCommandRunner $subCommandRunner)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption(
            'size',
            'S',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Set the profile size (CPU and memory) of apps, workers, or services.'
                . "\nItems are in the format <info>name:value</info> and may be comma-separated."
                . "\nThe % or * characters may be used as a wildcard for the name."
                . "\nList available sizes with the <info>resources:sizes</info> command."
                . "\nA value of 'default' will use the default size, and 'min' or 'minimum' will use the minimum.",
        )
            ->addOption(
                'count',
                'C',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Set the instance count of apps or workers.'
                . "\nItems are in the format <info>name:value</info> as above.",
            )
            ->addOption(
                'disk',
                'D',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Set the disk size (in MB) of apps or services.'
                . "\nItems are in the format <info>name:value</info> as above."
                . "\nA value of 'default' will use the default size, and 'min' or 'minimum' will use the minimum.",
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Try to run the update, even if it might exceed your limits')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes that would be made, without changing anything');

        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());

        $helpLines = [
            'Configure the resources allocated to apps, workers and services on an environment.',
            '',
            'The resources may be the profile size, the instance count, or the disk size (MB).',
            '',
            sprintf('Profile sizes are predefined CPU & memory values that can be viewed by running: <info>%s resources:sizes</info>', $this->config->getStr('application.executable')),
            '',
            'If the same service and resource is specified on the command line multiple times, only the final value will be used.',
            '',
            sprintf('You can also configure autoscaling by running <info>%s autoscaling:set</info>', $this->config->getStr('application.executable')),
        ];
        if ($this->config->has('service.resources_help_url')) {
            $helpLines[] = '';
            $helpLines[] = 'For more information on managing resources, see: <info>' . $this->config->getStr('service.resources_help_url') . '</info>';
        }
        $this->setHelp(implode("\n", $helpLines));

        $this->addExample('Set profile sizes for two apps and a service', '--size frontend:0.1,backend:.25,database:1');
        $this->addExample('Give the "backend" app 3 instances', '--count backend:3');
        $this->addExample('Give 512 MB disk to the "backend" app and 2 GB to the "database" service', '--disk backend:512,database:2048');
        $this->addExample('Set the same profile size for the "backend" and "frontend" apps using a wildcard', '--size ' . OsUtil::escapeShellArg('*end:0.1'));
        $this->addExample('Set the same instance count for all apps using a wildcard', '--count ' . OsUtil::escapeShellArg('*:3'));
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
                $this->stdErr->writeln(sprintf('The environment %s is not active so resources cannot be configured.', $this->api->getEnvironmentLabel($environment, 'comment')));
                return 1;
            }
            throw $e;
        }

        $services = $this->resourcesUtil->allServices($nextDeployment);
        if (empty($services)) {
            $this->stdErr->writeln('No apps or services found');
            return 1;
        }

        // Determine the limit of the number of instances, which can vary per project.
        $instanceLimit = null;
        if (($projectInfo = $nextDeployment->getProperty('project_info')) && isset($projectInfo['capabilities']['instance_limit'])) {
            $instanceLimit = $projectInfo['capabilities']['instance_limit'];
        }

        $autoscalingEnabled = [];
        // Check autoscaling settings for the environment, as autoscaling prevents changing some resources manually.
        $autoscalingSettings = $this->api->getAutoscalingSettings($environment);
        if ($autoscalingSettings) {
            foreach ($autoscalingSettings->getData()['services'] as $service => $serviceSettings) {
                $autoscalingEnabled[$service] = !empty($serviceSettings['enabled']);
            }
        }

        // Validate the --size option.
        [$givenSizes, $errored] = $this->parseSetting($input, 'size', $services, fn($v, $serviceName, $service) => $this->validateProfileSize($v, $serviceName, $service, $nextDeployment));

        // Validate the --count option.
        [$givenCounts, $countErrored] = $this->parseSetting($input, 'count', $services, fn($v, $serviceName, $service) => $this->validateInstanceCount($v, $serviceName, $service, $instanceLimit, !empty($autoscalingEnabled[$serviceName])));
        $errored = $errored || $countErrored;

        // Validate the --disk option.
        [$givenDiskSizes, $diskErrored] = $this->parseSetting($input, 'disk', $services, fn($v, $serviceName, $service) => $this->validateDiskSize($v, $serviceName, $service));
        $errored = $errored || $diskErrored;
        if ($errored) {
            return 1;
        }

        if (($exitCode = $this->subCommandRunner->run('resources:get', [
            '--project' => $environment->project,
            '--environment' => $environment->id,
        ], $this->stdErr)) !== 0) {
            return $exitCode;
        }
        $this->stdErr->writeln('');

        $containerProfiles = $nextDeployment->container_profiles;

        // Remove guaranteed profiles if project does not support it.
        $supportsGuaranteedCPU = $this->api->supportsGuaranteedCPU($selection->getProject(), $nextDeployment);
        foreach ($containerProfiles as $profileName => $profile) {
            foreach ($profile as $sizeName => $sizeInfo) {
                if (!$supportsGuaranteedCPU && $sizeInfo['cpu_type'] === 'guaranteed') {
                    unset($containerProfiles[$profileName][$sizeName]);
                }
            }
        }

        // Ask all questions if nothing was specified on the command line.
        $showCompleteForm = $input->isInteractive()
            && $input->getOption('size') === []
            && $input->getOption('count') === []
            && $input->getOption('disk') === [];

        $updates = [];
        $current = [];
        $hasGuaranteedCPU = false;
        foreach ($services as $name => $service) {
            $type = $this->typeName($service);
            $group = $this->group($service);

            $properties = $service->getProperties();
            $current[$group][$name]['resources']['profile_size'] = $properties['resources']['profile_size'];
            $current[$group][$name]['instance_count'] = $properties['instance_count'];
            $current[$group][$name]['disk'] = $properties['disk'];
            $current[$group][$name]['sizes'] = $containerProfiles[$properties['container_profile']];

            $header = '<options=bold>' . ucfirst($type) . ': </><options=bold,underscore>' . $name . '</>';
            $headerShown = false;
            $ensureHeader = function () use (&$headerShown, &$header): void {
                if (!$headerShown) {
                    $this->stdErr->writeln($header);
                    $this->stdErr->writeln('');
                }
                $headerShown = true;
            };

            // Set the profile size.
            if (isset($givenSizes[$name])) {
                if (!isset($properties['resources']['profile_size']) || $givenSizes[$name] != $properties['resources']['profile_size']) {
                    $updates[$group][$name]['resources']['profile_size'] = $givenSizes[$name];
                }
            } elseif ($showCompleteForm
                || (!isset($properties['resources']['profile_size']) && $input->isInteractive())) {
                if (isset($properties['container_profile'])) {
                    $header .= "\n" . sprintf('Container profile: <info>%s</info>', $properties['container_profile']);
                }
                $ensureHeader();
                $new = isset($properties['resources']['profile_size']) ? 'a new' : 'a';
                $profileSizes = $containerProfiles[$properties['container_profile']];
                if (isset($properties['resources']['profile_size'])) {
                    $defaultOption = $properties['resources']['profile_size'];
                } elseif (isset($properties['resources']['default']['profile_size'])) {
                    $defaultOption = $properties['resources']['default']['profile_size'];
                } else {
                    $defaultOption = null;
                }
                $options = [];
                foreach ($profileSizes as $profileSize => $sizeInfo) {
                    // Skip showing sizes that are below the minimum for this service.
                    if ((isset($properties['resources']['minimum']['cpu']) && $sizeInfo['cpu'] < $properties['resources']['minimum']['cpu'])
                      || (isset($properties['resources']['minimum']['memory']) && $sizeInfo['memory'] < $properties['resources']['minimum']['memory'])) {
                        continue;
                    }
                    $description = sprintf('CPU %s, memory %s MB (%s)', $sizeInfo['cpu'], $sizeInfo['memory'], $sizeInfo['cpu_type']);
                    if (isset($properties['resources']['profile_size'])
                        && $profileSize == $properties['resources']['profile_size']) {
                        $description .= ' <question>(current)</question>';
                    } elseif ($defaultOption !== null && $defaultOption === $profileSize) {
                        $description .= ' <question>(default)</question>';
                    }

                    $options[$profileSize] = $description;
                }

                if (!isset($properties['resources']['profile_size']) && empty($options)) {
                    $this->stdErr->writeln(sprintf('No profile size can be found for the %s <comment>%s</comment> which satisfies its minimum resources.', $type, $name));
                    $errored = true;
                } else {
                    $profileSize = $this->questionHelper->chooseAssoc($options, sprintf('Choose %s profile size:', $new), $defaultOption, false, false);
                    if (!isset($properties['resources']['profile_size']) || $profileSize !== $properties['resources']['profile_size']) {
                        $updates[$group][$name]['resources']['profile_size'] = $profileSize;
                    }
                }
            } elseif (!isset($properties['resources']['profile_size'])) {
                $this->stdErr->writeln(sprintf('A profile size is required for the %s <comment>%s</comment>.', $type, $name));
                $errored = true;
            }

            // Check if we have guaranteed CPU changes.
            if (isset($updates[$group][$name]['resources']['profile_size'])) {
                $serviceProfileSize = $updates[$group][$name]['resources']['profile_size'];
                $serviceProfileType = $properties['container_profile'];
                if (isset($containerProfiles[$serviceProfileType][$serviceProfileSize])
                    && $containerProfiles[$serviceProfileType][$serviceProfileSize]['cpu_type'] === 'guaranteed') {
                    $hasGuaranteedCPU = true;
                }
            }

            // Set the instance count.
            // This is not applicable to a Service, and unavailable when autoscaling is enabled.
            if (!$service instanceof Service && empty($autoscalingEnabled[$name])) {
                if (isset($givenCounts[$name])) {
                    $instanceCount = $givenCounts[$name];
                    if ($instanceCount !== $properties['instance_count'] && !($instanceCount === 1 && !isset($properties['instance_count']))) {
                        $updates[$group][$name]['instance_count'] = $instanceCount;
                    }
                } elseif ($showCompleteForm) {
                    $ensureHeader();
                    $default = $properties['instance_count'] ?: 1;
                    $instanceCount = $this->questionHelper->askInput(
                        'Enter the number of instances',
                        $default,
                        [],
                        fn($v) => $this->validateInstanceCount($v, $name, $service, $instanceLimit, false)
                    );
                    if ($instanceCount !== $properties['instance_count']) {
                        $updates[$group][$name]['instance_count'] = $instanceCount;
                    }
                }
            }

            // Set the disk size.
            if ($this->resourcesUtil->supportsDisk($service)) {
                if (isset($givenDiskSizes[$name])) {
                    if ($givenDiskSizes[$name] !== $service->disk) {
                        $updates[$group][$name]['disk'] = $givenDiskSizes[$name];
                    }
                } elseif ($showCompleteForm || (empty($service->disk) && $input->isInteractive())) {
                    $ensureHeader();
                    if ($service->disk) {
                        $default = $service->disk;
                    } else {
                        $default = $properties['resources']['default']['disk'] ?? '512';
                    }
                    $diskSize = $this->questionHelper->askInput('Enter a disk size in MB', $default, ['512', '1024', '2048'], fn($v) => $this->validateDiskSize($v, $name, $service));
                    if ($diskSize !== $service->disk) {
                        $updates[$group][$name]['disk'] = $diskSize;
                    }
                } elseif (empty($service->disk)) {
                    $this->stdErr->writeln(sprintf('A disk size is required for the %s <comment>%s</comment>.', $type, $name));
                    $errored = true;
                }
            }

            if ($headerShown) {
                $this->stdErr->writeln('');
            }
        }

        if ($errored) {
            return 1;
        }

        if (empty($updates)) {
            $this->stdErr->writeln('No resource changes were provided: nothing to update');
            return 0;
        }

        $this->summarizeChanges($updates, $services, $containerProfiles);

        $this->io->debug('Raw updates: ' . json_encode($updates, JSON_UNESCAPED_SLASHES));

        $project = $selection->getProject();
        $organization = $this->api->getClient()->getOrganizationById($project->getProperty('organization'));
        if (!$organization) {
            throw new \RuntimeException('Failed to load project organization: ' . $project->getProperty('organization'));
        }
        $profile = $organization->getProfile();
        if ($input->getOption('force') === false && isset($profile->resources_limit) && $profile->resources_limit) {
            $diff = $this->computeMemoryCPUStorageDiff($updates, $current);
            $limit = $profile->resources_limit['limit'];
            $used = $profile->resources_limit['used']['totals'];

            $this->io->debug('Raw diff: ' . json_encode($diff, JSON_UNESCAPED_SLASHES));
            $this->io->debug('Raw limits: ' . json_encode($limit, JSON_UNESCAPED_SLASHES));
            $this->io->debug('Raw used: ' . json_encode($used, JSON_UNESCAPED_SLASHES));

            $errored = false;
            if ($limit['cpu'] < $used['cpu'] + $diff['cpu']) {
                $this->stdErr->writeln(sprintf(
                    'The requested resources will exceed your organization\'s trial CPU limit, which is: <comment>%s</comment>.',
                    $limit['cpu'],
                ));
                $errored = true;
            }

            if ($limit['memory'] < $used['memory'] + ($diff['memory'] / 1024)) {
                $this->stdErr->writeln(sprintf(
                    'The requested resources will exceed your organization\'s trial memory limit, which is: <comment>%sGB</comment>.',
                    $limit['memory'],
                ));
                $errored = true;
            }

            if ($limit['storage'] < $used['storage'] + ($diff['disk'] / 1024)) {
                $this->stdErr->writeln(sprintf(
                    'The requested resources will exceed your organization\'s trial storage limit, which is: <comment>%sGB</comment>.',
                    $limit['storage'],
                ));
                $errored = true;
            }

            if ($errored) {
                $this->stdErr->writeln('Please adjust your resources or activate your subscription.');
                return 1;
            }
        }

        if ($input->getOption('dry-run')) {
            return 0;
        }

        $this->stdErr->writeln('');

        $questionText = 'Are you sure you want to continue?';
        if ($hasGuaranteedCPU && $this->config->has('warnings.guaranteed_resources_msg')) {
            $questionText = trim($this->config->getStr('warnings.guaranteed_resources_msg'))
                . "\n\n" . "Are you sure you want to continue?";
        }
        if (!$this->questionHelper->confirm($questionText)) {
            return 1;
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Setting the resources on the environment ' . $this->api->getEnvironmentLabel($environment));
        $result = $nextDeployment->update($updates);

        if ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Summarizes all the changes that would be made.
     *
     * @param array<array<string, array<string, mixed>>> $updates
     * @param array<string, WebApp|Worker|Service> $services
     * @param array<string, mixed> $containerProfiles
     * @return void
     */
    private function summarizeChanges(array $updates, array $services, array $containerProfiles): void
    {
        $this->stdErr->writeln('<options=bold>Summary of changes:</>');
        foreach ($updates as $groupUpdates) {
            foreach ($groupUpdates as $serviceName => $serviceUpdates) {
                $this->summarizeChangesPerService($serviceName, $services[$serviceName], $serviceUpdates, $containerProfiles);
            }
        }
    }

    /**
     * Summarizes changes per service.
     *
     * @param array<string, mixed> $updates
     * @param array<string, mixed> $containerProfiles
     */
    private function summarizeChangesPerService(string $name, WebApp|Worker|Service $service, array $updates, array $containerProfiles): void
    {
        $this->stdErr->writeln(sprintf('  <options=bold>%s: </><options=bold,underscore>%s</>', ucfirst($this->typeName($service)), $name));

        $properties = $service->getProperties();
        if (isset($updates['resources']['profile_size'])) {
            $sizeInfo = $this->resourcesUtil->sizeInfo($properties, $containerProfiles);
            $newProperties = array_replace_recursive($properties, $updates);

            $newSizeInfo = $this->resourcesUtil->sizeInfo($newProperties, $containerProfiles);
            $this->stdErr->writeln('    CPU: ' . $this->resourcesUtil->formatChange(
                $this->resourcesUtil->formatCPU($sizeInfo ? $sizeInfo['cpu'] : null) . ' ' . $this->formatCPUType($sizeInfo),
                $this->resourcesUtil->formatCPU($newSizeInfo['cpu']) . ' ' . $this->formatCPUType($newSizeInfo)
            ));
            $this->stdErr->writeln('    Memory: ' . $this->resourcesUtil->formatChange(
                $sizeInfo ? $sizeInfo['memory'] : null,
                $newSizeInfo['memory'],
                ' MB',
            ));
        }
        if (isset($updates['instance_count'])) {
            $this->stdErr->writeln('    Instance count: ' . $this->resourcesUtil->formatChange(
                $properties['instance_count'] ?? 1,
                $updates['instance_count'],
            ));
        }
        if (isset($updates['disk'])) {
            $this->stdErr->writeln('    Disk: ' . $this->resourcesUtil->formatChange(
                $properties['disk'] ?? null,
                $updates['disk'],
                ' MB',
            ));
        }
    }

    /**
     * Returns the group for a service (where it belongs in the deployment object).
     */
    protected function group(WebApp|Worker|Service $service): string
    {
        if ($service instanceof WebApp) {
            return 'webapps';
        }
        if ($service instanceof Worker) {
            return 'workers';
        }
        return 'services';
    }

    /**
     * Returns the service type name for a service.
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
    protected function validateInstanceCount(string $value, string $serviceName, WebApp|Worker|Service $service, ?int $limit, bool $autoscalingEnabled): int
    {
        if ($service instanceof Service) {
            throw new InvalidArgumentException(sprintf('The instance count of the service <error>%s</error> cannot be changed.', $serviceName));
        }
        if ($autoscalingEnabled) {
            throw new InvalidArgumentException(sprintf('The instance count of the %s <error>%s</error> cannot be changed when autoscaling is enabled.', $this->typeName($service), $serviceName));
        }
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
     * Validates a given disk size.
     *
     * @throws InvalidArgumentException
     */
    protected function validateDiskSize(string $value, string $serviceName, WebApp|Worker|Service $service): int
    {
        if (!$this->resourcesUtil->supportsDisk($service)) {
            throw new InvalidArgumentException(sprintf(
                'The %s <error>%s</error> does not support a persistent disk.',
                $this->typeName($service),
                $serviceName,
            ));
        }
        $size = (int) $value;
        if ($size != $value || $value < 0) {
            throw new InvalidArgumentException(sprintf(
                'Invalid disk size <error>%s</error>: it must be an integer in MB.',
                $value,
            ));
        }
        $properties = $service->getProperties();
        if ($value === 'default') {
            if (!isset($properties['resources']['default']['disk'])) {
                throw new \RuntimeException(sprintf('Default disk size not found for service %s', $serviceName));
            }
            return $properties['resources']['default']['disk'];
        }
        if ($value === 'minimum' || $value === 'min') {
            if (!isset($properties['resources']['minimum']['disk'])) {
                throw new \RuntimeException(sprintf('Minimum disk size not found for service %s', $serviceName));
            }
            return $properties['resources']['minimum']['disk'];
        }
        if (isset($properties['resources']['minimum']['disk']) && $value < $properties['resources']['minimum']['disk']) {
            throw new InvalidArgumentException(sprintf(
                'Invalid disk size <error>%s</error>: the minimum size for this %s is <error>%d</error> MB.',
                $value,
                $this->typeName($service),
                $properties['resources']['minimum']['disk'],
            ));
        }
        return $size;
    }

    /**
     * Validates a given profile size.
     *
     * @throws InvalidArgumentException
     */
    protected function validateProfileSize(string $value, string $serviceName, WebApp|Worker|Service $service, EnvironmentDeployment $deployment): string
    {
        $properties = $service->getProperties();
        if ($value === 'default') {
            if (!isset($properties['resources']['default']['profile_size'])) {
                throw new \RuntimeException(sprintf('Default profile size not found for service %s', $serviceName));
            }
            return $properties['resources']['default']['profile_size'];
        }
        if ($value === 'minimum' || $value === 'min') {
            if (!isset($properties['resources']['minimum']['profile_size'])) {
                throw new \RuntimeException(sprintf('Minimum profile size not found for service %s', $serviceName));
            }
            return $properties['resources']['minimum']['profile_size'];
        }
        $containerProfile = $properties['container_profile'];
        if (!isset($deployment->container_profiles[$containerProfile])) {
            throw new \RuntimeException(sprintf('Container profile %s for service %s not found', $containerProfile, $serviceName));
        }
        $resources = $service->getProperty('resources', false);
        $profile = $deployment->container_profiles[$containerProfile];
        foreach ($profile as $sizeName => $sizeInfo) {
            // Loosely compare the value with the container profile size.
            if ($value == $sizeName) {
                if (isset($resources['minimum']['cpu'], $sizeInfo['cpu']) && $sizeInfo['cpu'] < $resources['minimum']['cpu']) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid profile size <error>%s</error>: its CPU amount %d is below the minimum for this %s, %d',
                        $sizeName,
                        $sizeInfo['cpu'],
                        $this->typeName($service),
                        $resources['minimum']['cpu'],
                    ));
                }
                if (isset($resources['minimum']['memory'], $sizeInfo['memory']) && $sizeInfo['memory'] < $resources['minimum']['memory']) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid profile size <error>%s</error>: its memory amount %d MB is below the minimum for this %s, %d MB',
                        $sizeName,
                        $sizeInfo['memory'],
                        $this->typeName($service),
                        $resources['minimum']['memory'],
                    ));
                }
                return (string) $sizeName;
            }
        }
        throw new InvalidArgumentException(sprintf('Size <error>%s</error> not found in container profile <comment>%s</comment>; the available sizes are: <comment>%s</comment>', $value, $containerProfile, implode('</comment>, <comment>', array_keys($profile))));
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
    private function parseSetting(InputInterface $input, string $optionName, array $services, ?callable $validator): array
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
            [$pattern, $value] = $parts;
            $givenServiceNames = Wildcard::select($serviceNames, [$pattern]);
            if (empty($givenServiceNames)) {
                $errors[] = sprintf('App or service <error>%s</error> not found.', $pattern);
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
                    $this->io->debug(sprintf('Overriding value %s with %s for %s in --%s', $values[$name], $normalized, $name, $optionName));
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
     * @param string[] $errors
     * @param string $optionName
     *
     * @return string[]
     */
    private function formatErrors(array $errors, string $optionName): array
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

    /**
     * Compute the total memory/CPU/storage diff that will occur when the given update
     * is applied.
     *
     * @param array<string, array<string, array<string, mixed>>> $updates
     * @param array<string, array<string, array<string, mixed>>> $current
     *
     * @return array{memory: int|float, cpu: int|float, disk: int|float}
     */
    private function computeMemoryCPUStorageDiff(array $updates, array $current): array
    {
        $diff = [
            'memory' => 0,
            'cpu' => 0,
            'disk' => 0,
        ];
        foreach ($updates as $group => $groupUpdates) {
            foreach ($groupUpdates as $serviceName => $serviceUpdates) {
                if (isset($current[$group][$serviceName]['instance_count']) === false) {
                    $current[$group][$serviceName]['instance_count'] = 1;
                }
                if (isset($current[$group][$serviceName]['disk']) === false) {
                    $current[$group][$serviceName]['disk'] = 0;
                }

                $currentCount = $current[$group][$serviceName]['instance_count'];
                $currentSize = $current[$group][$serviceName]['resources']['profile_size'];
                $currentStorage = $current[$group][$serviceName]['disk'];

                $newCount = $currentCount;
                $newSize = $currentSize;
                $newStorage = $currentStorage;
                if (isset($serviceUpdates['instance_count'])) {
                    $newCount = $serviceUpdates['instance_count'];
                }
                if (isset($serviceUpdates['resources'])) {
                    $newSize = $serviceUpdates['resources']['profile_size'];
                }
                if (isset($serviceUpdates['disk'])) {
                    $newStorage = $serviceUpdates['disk'];
                }

                $currentService = $current[$group][$serviceName];
                $currentSize = $currentService['resources']['profile_size'];
                $currentProfile = $currentService['sizes'][$currentSize];
                $currentCPU = $currentCount * $currentProfile['cpu'];
                $currentRAM = $currentCount * $currentProfile['memory'];

                $newProfile = $currentService['sizes'][$newSize];
                $newCPU = $newCount * $newProfile['cpu'];
                $newRAM = $newCount * $newProfile['memory'];

                $diff['memory'] += $newRAM - $currentRAM;
                $diff['cpu'] += $newCPU - $currentCPU;
                $diff['disk'] += $newStorage - $currentStorage;
            }
        }

        return $diff;
    }
}
