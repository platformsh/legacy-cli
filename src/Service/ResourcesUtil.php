<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Util\StringUtil;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Platformsh\Client\Model\Deployment\Service;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Deployment\Worker;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResourcesUtil
{
    private readonly OutputInterface $stdErr;

    /** @var array<string, EnvironmentDeployment> */
    private static array $cachedNextDeployment = [];

    public function __construct(private readonly Api $api, private readonly Config $config, OutputInterface $output)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    public function featureEnabled(): bool
    {
        return $this->config->getBool('api.sizing');
    }

    /**
     * Lists services in a deployment.
     *
     * @param EnvironmentDeployment $deployment
     *
     * @return array<string, WebApp|Worker|Service>
     *     An array of services keyed by the service name.
     */
    public function allServices(EnvironmentDeployment $deployment): array
    {
        $webapps = $deployment->webapps;
        $workers = $deployment->workers;
        $services = $deployment->services;
        ksort($webapps, SORT_STRING | SORT_FLAG_CASE);
        ksort($workers, SORT_STRING | SORT_FLAG_CASE);
        ksort($services, SORT_STRING | SORT_FLAG_CASE);
        return array_merge($webapps, $workers, $services);
    }

    /**
     * Checks whether a service needs a persistent disk.
     */
    public function supportsDisk(WebApp|Worker|Service $service): bool
    {
        // Workers use the disk of their parent app.
        if ($service instanceof Worker) {
            return false;
        }
        return isset($service->getProperties()['resources']['minimum']['disk']);
    }

    /**
     * Loads the next environment deployment and caches it statically.
     *
     * The static cache means it can be reused while running a sub-command.
     */
    public function loadNextDeployment(Environment $environment, bool $reset = false): EnvironmentDeployment
    {
        $cacheKey = $environment->project . ':' . $environment->id;
        if (isset(self::$cachedNextDeployment[$cacheKey]) && !$reset) {
            return self::$cachedNextDeployment[$cacheKey];
        }
        $progress = new ProgressMessage($this->stdErr);
        try {
            $progress->show('Loading deployment information...');
            $next = $environment->getNextDeployment();
            if (!$next) {
                throw new EnvironmentStateException('No next deployment found', $environment);
            }
        } finally {
            $progress->done();
        }
        return self::$cachedNextDeployment[$cacheKey] = $next;
    }

    /**
     * Filters a list of services according to the --service or --type options.
     *
     * @param array<string, WebApp|Service|Worker> $services
     * @param InputInterface $input
     *
     * @return WebApp[]|Service[]|Worker[]|false
     *   False on error, or an array of services.
     */
    public function filterServices(array $services, InputInterface $input): array|false
    {
        $selectedNames = [];

        $requestedServices = ArrayArgument::getOption($input, 'service');
        if (!empty($requestedServices)) {
            $selectedNames = Wildcard::select(array_keys($services), $requestedServices);
            if (!$selectedNames) {
                $this->stdErr->writeln('No services were found matching the name(s): <error>' . implode('</error>, <error>', $requestedServices) . '</error>');
                return false;
            }
            $services = array_intersect_key($services, array_flip($selectedNames));
        }
        $requestedApps = ArrayArgument::getOption($input, 'app');
        if (!empty($requestedApps)) {
            $selectedNames = Wildcard::select(array_keys(array_filter($services, fn($s): bool => $s instanceof WebApp)), $requestedApps);
            if (!$selectedNames) {
                $this->stdErr->writeln('No applications were found matching the name(s): <error>' . implode('</error>, <error>', $requestedApps) . '</error>');
                return false;
            }
            $services = array_intersect_key($services, array_flip($selectedNames));
        }
        $requestedWorkers = ArrayArgument::getOption($input, 'worker');
        if (!empty($requestedWorkers)) {
            $selectedNames = Wildcard::select(array_keys(array_filter($services, fn($s): bool => $s instanceof Worker)), $requestedWorkers);
            if (!$selectedNames) {
                $this->stdErr->writeln('No workers were found matching the name(s): <error>' . implode('</error>, <error>', $requestedWorkers) . '</error>');
                return false;
            }
            $services = array_intersect_key($services, array_flip($selectedNames));
        }

        if ($input->hasOption('type') && ($requestedTypes = ArrayArgument::getOption($input, 'type'))) {
            $byType = [];
            foreach ($services as $name => $service) {
                $type = $service->type;
                [$prefix] = explode(':', $service->type, 2);
                $byType[$type][] = $name;
                $byType[$prefix][] = $name;
            }
            $selectedTypes = Wildcard::select(array_keys($byType), $requestedTypes);
            if (!$selectedTypes) {
                $this->stdErr->writeln('No services were found matching the type(s): <error>' . implode('</error>, <error>', $requestedTypes) . '</error>');
                return false;
            }
            foreach ($selectedTypes as $selectedType) {
                $selectedNames = array_merge($selectedNames, $byType[$selectedType]);
            }
            $services = array_intersect_key($services, array_flip($selectedNames));
        }

        return $services;
    }

    /**
     * Returns container profile size info, given service properties.
     *
     * @param array<string, mixed> $properties
     *   The service properties (e.g. from $service->getProperties()).
     * @param array<string, mixed> $containerProfiles
     *   The list of container profiles (e.g. from
     *   $deployment->container_profiles).
     *
     * @return array{'cpu': string, 'memory': string}|null
     */
    public function sizeInfo(array $properties, array $containerProfiles): ?array
    {
        if (isset($properties['resources']['profile_size'])) {
            $size = $properties['resources']['profile_size'];
            $profile = $properties['container_profile'];
            if (isset($containerProfiles[$profile][$size])) {
                return $containerProfiles[$profile][$size];
            }
        }
        return null;
    }

    /**
     * Formats a change in a value.
     *
     * @param int|float|string|null $previousValue
     * @param int|float|string|null $newValue
     * @param string $suffix A unit suffix e.g. ' MB'
     * @param callable|null $comparator
     *
     * @return string
     */
    public function formatChange(int|float|string|null $previousValue, int|float|string|null $newValue, string $suffix = '', ?callable $comparator = null): string
    {
        if ($previousValue === null || $newValue === $previousValue) {
            return sprintf('<info>%s%s</info>', $newValue, $suffix);
        }
        if ($comparator !== null) {
            $changeText = $comparator($previousValue, $newValue) ? '<fg=green>increasing</>' : '<fg=yellow>decreasing</>';
        } elseif ($newValue === "true" || $newValue === "false") {
            $color = $newValue === "true" ? 'green' : 'yellow';
            $changeText = '<fg=' . $color . '>changing</>';
        } else {
            $changeText = $newValue > $previousValue ? '<fg=green>increasing</>' : '<fg=yellow>decreasing</>';
        }
        return sprintf(
            '%s from %s%s to <info>%s%s</info>',
            $changeText,
            $previousValue,
            $suffix,
            $newValue,
            $suffix
        );
    }

    /**
     * Formats a CPU amount.
     *
     * @param int|float|string $unformatted
     *
     * @return string
     *   A numeric (still comparable) string with 1 decimal place.
     */
    public function formatCPU(int|float|string $unformatted): string
    {
        return sprintf('%.1f', $unformatted);
    }

    /**
     * Adds a --resources-init option to commands that support it.
     *
     * The option will only be added if the api.sizing feature is enabled.
     *
     * @param InputDefinition $definition
     * @param string[] $values
     *   The possible values, with the default as the first element.
     * @param string $description
     *
     * @return self
     *
     * @see ResourcesUtil::validateResourcesInitInput()
     */
    public function addOption(InputDefinition $definition, array $values, string $description = ''): self
    {
        if (!$this->featureEnabled()) {
            return $this;
        }
        if ($description === '') {
            $description = 'Set the resources to use for new services';
            $description .= ': ' . StringUtil::formatItemList($values);
            $default = array_shift($values);
            $description .= ".\n" . sprintf('If not set, "%s" will be used.', $default);
        }
        $definition->addOption(new InputOption('resources-init', null, InputOption::VALUE_REQUIRED, $description));

        return $this;
    }

    /**
     * Validates and returns the --resources-init input, if any.
     *
     * @param InputInterface $input
     * @param Project $project
     * @param string[] $allowedValues
     * @return string|false|null
     *   The input value, or false if there was a validation error, or null if
     *   nothing was specified or the input option didn't exist.
     *
     * @see ResourcesUtil::addResourcesInitOption()
     */
    public function validateInput(InputInterface $input, Project $project, array $allowedValues): false|string|null
    {
        $resourcesInit = $input->hasOption('resources-init') ? $input->getOption('resources-init') : null;
        if ($resourcesInit !== null) {
            if (!\in_array($resourcesInit, $allowedValues, true)) {
                $this->stdErr->writeln('The value for <error>--resources-init</error> must be one of: ' . \implode(', ', $allowedValues));
                return false;
            }
            if (!$this->api->supportsSizingApi($project)) {
                $this->stdErr->writeln('The <comment>--resources-init</comment> option cannot be used as the project does not support flexible resources.');
                return false;
            }
        }
        return $resourcesInit;
    }
}
