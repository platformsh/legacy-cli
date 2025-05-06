<?php

namespace Platformsh\Cli\Command\Resources;

use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Platformsh\Client\Model\Deployment\Service;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Deployment\Worker;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ResourcesSetCommand extends ResourcesCommandBase
{
    protected function configure()
    {
        $this->setName('resources:set')
            ->setDescription('Set the resources of apps and services on an environment')
            ->addOption('size', 'S', InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY,
                'Set the profile size (CPU and memory) of apps, workers, or services.'
                . "\nItems are in the format <info>name:value</info> and may be comma-separated."
                . "\nThe % or * characters may be used as a wildcard for the name."
                . "\nList available sizes with the <info>resources:sizes</info> command."
                . "\nA value of 'default' will use the default size, and 'min' or 'minimum' will use the minimum."
            )
            ->addOption('count', 'C', InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY,
                'Set the instance count of apps or workers.'
                . "\nItems are in the format <info>name:value</info> as above."
            )
            ->addOption('disk', 'D', InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY,
                'Set the disk size (in MB) of apps or services.'
                . "\nItems are in the format <info>name:value</info> as above."
                . "\nA value of 'default' will use the default size, and 'min' or 'minimum' will use the minimum."
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Try to run the update, even if it might exceed your limits')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes that would be made, without changing anything')
            ->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();

        $helpLines = [
            'Configure the resources allocated to apps, workers and services on an environment.',
            '',
            'The resources may be the profile size, the instance count, or the disk size (MB).',
            '',
            sprintf('Profile sizes are predefined CPU & memory values that can be viewed by running: <info>%s resources:sizes</info>', $this->config()->get('application.executable')),
            '',
            'If the same service and resource is specified on the command line multiple times, only the final value will be used.'
        ];
        if ($this->config()->has('service.resources_help_url')) {
            $helpLines[] = '';
            $helpLines[] = 'For more information on managing resources, see: <info>' . $this->config()->get('service.resources_help_url') . '</info>';
        }
        $this->setHelp(implode("\n", $helpLines));

        $this->addExample('Set profile sizes for two apps and a service', '--size frontend:0.1,backend:.25,database:1');
        $this->addExample('Give the "backend" app 3 instances', '--count backend:3');
        $this->addExample('Give 512 MB disk to the "backend" app and 2 GB to the "database" service', '--disk backend:512,database:2048');
        $this->addExample('Set the same profile size for the "backend" and "frontend" apps using a wildcard', '--size ' . OsUtil::escapeShellArg('*end:0.1'));
        $this->addExample('Set the same instance count for all apps using a wildcard', '--count ' . OsUtil::escapeShellArg('*:3'));
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
                $this->stdErr->writeln(sprintf('The environment %s is not active so resources cannot be configured.', $this->api()->getEnvironmentLabel($environment, 'comment')));
                return 1;
            }
            throw $e;
        }

        $services = $this->allServices($nextDeployment);
        if (empty($services)) {
            $this->stdErr->writeln('No apps or services found');
            return 1;
        }

        // Determine the limit of the number of instances, which can vary per project.
        $instanceLimit = null;
        if (($projectInfo = $nextDeployment->getProperty('project_info')) && isset($projectInfo['capabilities']['instance_limit'])) {
            $instanceLimit = $projectInfo['capabilities']['instance_limit'];
        }

        // Validate the --size option.
        list($givenSizes, $errored) = $this->parseSetting($input, 'size', $services, function ($v, $serviceName, $service) use ($nextDeployment) {
            return $this->validateProfileSize($v, $serviceName, $service, $nextDeployment);
        });

        // Validate the --count option.
        list($givenCounts, $countErrored) = $this->parseSetting($input, 'count', $services, function ($v, $serviceName, $service) use ($instanceLimit) {
            return $this->validateInstanceCount($v, $serviceName, $service, $instanceLimit);
        });
        $errored = $errored || $countErrored;

        // Validate the --disk option.
        list($givenDiskSizes, $diskErrored) = $this->parseSetting($input, 'disk', $services, function ($v, $serviceName, $service) {
            return $this->validateDiskSize($v, $serviceName, $service);
        });
        $errored = $errored || $diskErrored;
        if ($errored) {
            return 1;
        }

        if (($exitCode = $this->runOtherCommand('resources:get', [
                '--project' => $environment->project,
                '--environment' => $environment->id,
            ], $this->stdErr)) !== 0) {
            return $exitCode;
        }
        $this->stdErr->writeln('');

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $containerProfiles = $nextDeployment->container_profiles;

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
            $ensureHeader = function () use (&$headerShown, &$header) {
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
                    $description = sprintf('CPU %s, memory %s MB', $sizeInfo['cpu'], $sizeInfo['memory']);
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
                    $profileSize = $questionHelper->chooseAssoc($options, sprintf('Choose %s profile size:', $new), $defaultOption, false, false);
                    if (!isset($properties['resources']['profile_size']) || $profileSize != $properties['resources']['profile_size']) {
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
                if (isset($containerProfiles[$serviceProfileType][$serviceProfileSize]) && $containerProfiles[$serviceProfileType][$serviceProfileSize]['type'] == 'guaranteed') {
                    $hasGuaranteedCPU = true;
                }
            }

            // Set the instance count.
            if (!$service instanceof Service) { // a Service instance count cannot be changed
                if (isset($givenCounts[$name])) {
                    $instanceCount = $givenCounts[$name];
                    if ($instanceCount !== $properties['instance_count'] && !($instanceCount === 1 && !isset($properties['instance_count']))) {
                        $updates[$group][$name]['instance_count'] = $instanceCount;
                    }
                } elseif ($showCompleteForm) {
                    $ensureHeader();
                    $default = $properties['instance_count'] ?: 1;
                    $instanceCount = $questionHelper->askInput('Enter the number of instances', $default, [], function ($v) use ($name, $service, $instanceLimit) {
                        return $this->validateInstanceCount($v, $name, $service, $instanceLimit);
                    });
                    if ($instanceCount !== $properties['instance_count']) {
                        $updates[$group][$name]['instance_count'] = $instanceCount;
                    }
                }
            }

            // Set the disk size.
            if ($this->supportsDisk($service)) {
                if (isset($givenDiskSizes[$name])) {
                    if ($givenDiskSizes[$name] !== $service->disk) {
                        $updates[$group][$name]['disk'] = $givenDiskSizes[$name];
                    }
                } elseif ($showCompleteForm || (empty($service->disk) && $input->isInteractive())) {
                    $ensureHeader();
                    if ($service->disk) {
                        $default = $service->disk;
                    } else {
                        $default = isset($properties['resources']['default']['disk']) ? $properties['resources']['default']['disk'] : '512';
                    }
                    $diskSize = $questionHelper->askInput('Enter a disk size in MB', $default, ['512', '1024', '2048'],  function ($v) use ($name, $service) {
                        return $this->validateDiskSize($v, $name, $service);
                    });
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

        $this->debug('Raw updates: ' . json_encode($updates, JSON_UNESCAPED_SLASHES));

        $project = $this->getSelectedProject();
        $organization = $this->api()->getClient()->getOrganizationById($project->getProperty('organization'));
        $profile = $organization->getProfile();
        if ($input->getOption('force') === false && isset($profile->resources_limit) && $profile->resources_limit) {
            $diff = $this->computeMemoryCPUStorageDiff($updates, $current);
            $limit = $profile->resources_limit['limit'];
            $used = $profile->resources_limit['used']['totals'];

            $this->debug('Raw diff: ' . json_encode($diff, JSON_UNESCAPED_SLASHES));
            $this->debug('Raw limits: ' . json_encode($limit, JSON_UNESCAPED_SLASHES));
            $this->debug('Raw used: ' . json_encode($used, JSON_UNESCAPED_SLASHES));

            $errored = false;
            if ($limit['cpu'] < $used['cpu'] + $diff['cpu']) {
                $this->stdErr->writeln(sprintf(
                    'The requested resources will exceed your organization\'s trial CPU limit, which is: <comment>%s</comment>.',
                    $limit['cpu']
                ));
                $errored = true;
            }

            if ($limit['memory'] < $used['memory'] + ($diff['memory'] / 1024)) {
                $this->stdErr->writeln(sprintf(
                    'The requested resources will exceed your organization\'s trial memory limit, which is: <comment>%sGB</comment>.',
                    $limit['memory']
                ));
                $errored = true;
            }

            if ($limit['storage'] < $used['storage'] + ($diff['disk'] / 1024)) {
                $this->stdErr->writeln(sprintf(
                    'The requested resources will exceed your organization\'s trial storage limit, which is: <comment>%sGB</comment>.',
                    $limit['storage']
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
        if ($hasGuaranteedCPU) {
            $questionText = 'You have chosen to allocate guaranteed resources across your chosen apps and services.
This change will increase your resource costs.
Please make sure you have reviewed our pricing page (https://upsun.com/pricing/).

This process requires a redeployment of your containers on their own host, which may take up to 7 minutes to complete.
Would you like to continue?';
        }
        if (!$questionHelper->confirm($questionText)) {
            return 1;
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Setting the resources on the environment ' . $this->api()->getEnvironmentLabel($environment));
        $result = $nextDeployment->update($updates);

        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Summarizes all the changes that would be made.
     *
     * @param array $updates
     * @param array<string, WebApp|Worker|Service> $services
     * @param array $containerProfiles
     * @return void
     */
    private function summarizeChanges(array $updates, $services, array $containerProfiles)
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
     * @param string $name The service name
     * @param WebApp|Worker|Service $service
     * @param array $updates
     * @param array $containerProfiles
     * @return void
     */
    private function summarizeChangesPerService($name, $service, array $updates, array $containerProfiles)
    {
        $this->stdErr->writeln(sprintf('  <options=bold>%s: </><options=bold,underscore>%s</>', ucfirst($this->typeName($service)), $name));

        $properties = $service->getProperties();
        if (isset($updates['resources']['profile_size'])) {
            $sizeInfo = $this->sizeInfo($properties, $containerProfiles);
            $newProperties = array_replace_recursive($properties, $updates);
            $newSizeInfo = $this->sizeInfo($newProperties, $containerProfiles);
            $this->stdErr->writeln('    CPU: ' . $this->formatChange(
                $this->formatCPU($sizeInfo ? $sizeInfo['cpu'] : null),
                $this->formatCPU($newSizeInfo['cpu'])
            ));
            $this->stdErr->writeln('    Memory: ' . $this->formatChange(
                $sizeInfo ? $sizeInfo['memory'] : null,
                $newSizeInfo['memory'],
            ' MB'
            ));
        }
        if (isset($updates['instance_count'])) {
            $this->stdErr->writeln('    Instance count: ' . $this->formatChange(
                isset($properties['instance_count']) ? $properties['instance_count'] : 1,
                $updates['instance_count']
            ));
        }
        if (isset($updates['disk'])) {
            $this->stdErr->writeln('    Disk: ' . $this->formatChange(
                isset($properties['disk']) ? $properties['disk'] : null,
                $updates['disk'],
                ' MB'
            ));
        }
    }

    /**
     * Returns the group for a service (where it belongs in the deployment object).
     *
     * @param Service|WebApp|Worker $service
     * @return string
     */
    protected function group($service)
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
     * @param string $serviceName
     * @param Service|WebApp|Worker $service
     * @param int|null $limit
     *
     * @throws InvalidArgumentException
     *
     * @return int
     */
    protected function validateInstanceCount($value, $serviceName, $service, $limit)
    {
        if ($service instanceof Service) {
            throw new InvalidArgumentException(sprintf('The instance count of the service <error>%s</error> cannot be changed.', $serviceName));
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
     * Validate a given disk size.
     *
     * @param string $value
     * @param string $serviceName
     * @param Service|WebApp|Worker $service
     *
     * @throws InvalidArgumentException
     *
     * @return int
     */
    protected function validateDiskSize($value, $serviceName, $service)
    {
        if (!$this->supportsDisk($service)) {
            throw new InvalidArgumentException(sprintf(
                'The %s <error>%s</error> does not support a persistent disk.', $this->typeName($service), $serviceName
            ));
        }
        $size = (int) $value;
        if ($size != $value || $value < 0) {
            throw new InvalidArgumentException(sprintf(
                'Invalid disk size <error>%s</error>: it must be an integer in MB.', $value
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
                $value, $this->typeName($service), $properties['resources']['minimum']['disk']
            ));
        }
        return $size;
    }

    /**
     * Validate a given profile size.
     *
     * @param string $value
     * @param string $serviceName
     * @param Service|WebApp|Worker $service
     * @param EnvironmentDeployment $deployment
     *
     * @throws InvalidArgumentException
     *
     * @return string
     */
    protected function validateProfileSize($value, $serviceName, $service, EnvironmentDeployment $deployment)
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
                        $sizeName, $sizeInfo['cpu'], $this->typeName($service), $resources['minimum']['cpu']
                    ));
                }
                if (isset($resources['minimum']['memory'], $sizeInfo['memory']) && $sizeInfo['memory'] < $resources['minimum']['memory']) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid profile size <error>%s</error>: its memory amount %d MB is below the minimum for this %s, %d MB',
                        $sizeName, $sizeInfo['memory'], $this->typeName($service), $resources['minimum']['memory']
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

    /**
     * Compute the total memory/CPU/storage diff that will occur when the given update
     * is applied.
     *
     * @param array $updates
     * @param array $current
     *
     * @return array
     */
    private function computeMemoryCPUStorageDiff(array $updates, array $current)
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
