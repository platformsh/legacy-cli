<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Resources;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Model\Deployment\Service;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Deployment\Worker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Contracts\Service\Attribute\Required;

class ResourcesCommandBase extends CommandBase
{
    private Config $config;

    #[Required]
    public function autowire(Config $config): void
    {
        $this->config = $config;
    }

    public function isHidden(): bool
    {
        return !$this->config->getBool('api.sizing') || parent::isHidden();
    }

    /**
     * Checks whether a service needs a persistent disk.
     *
     * @param WebApp|Service|Worker $service
     * @return bool
     */
    protected function supportsDisk($service)
    {
        // Workers use the disk of their parent app.
        if ($service instanceof Worker) {
            return false;
        }
        return isset($service->getProperties()['resources']['minimum']['disk']);
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
    protected function filterServices($services, InputInterface $input)
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
            $selectedNames = Wildcard::select(array_keys(array_filter($services, function ($s) { return $s instanceof WebApp; })), $requestedApps);
            if (!$selectedNames) {
                $this->stdErr->writeln('No applications were found matching the name(s): <error>' . implode('</error>, <error>', $requestedApps) . '</error>');
                return false;
            }
            $services = array_intersect_key($services, array_flip($selectedNames));
        }
        $requestedWorkers = ArrayArgument::getOption($input, 'worker');
        if (!empty($requestedWorkers)) {
            $selectedNames = Wildcard::select(array_keys(array_filter($services, function ($s) { return $s instanceof Worker; })), $requestedWorkers);
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
     * @param array<string, array<string, array<string, string>>> $containerProfiles
     *   The list of container profiles (e.g. from
     *   $deployment->container_profiles).
     *
     * @return array<string, string>|null
     */
    protected function sizeInfo(array $properties, array $containerProfiles)
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
     *
     * @return string
     */
    protected function formatChange($previousValue, $newValue, $suffix = '')
    {
        if ($previousValue === null || $newValue === $previousValue) {
            return sprintf('<info>%s%s</info>', $newValue, $suffix);
        }
        return sprintf(
            '%s from %s%s to <info>%s%s</info>',
            $newValue > $previousValue ? '<fg=green>increasing</>' : '<fg=yellow>decreasing</>',
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
    protected function formatCPU($unformatted)
    {
        return sprintf('%.1f', $unformatted);
    }

    /**
     * Format CPU Type.
     *
     * @param array<string, string>|null $sizeInfo
     *
     * @return string
     */
    protected function formatCPUType($sizeInfo)
    {
        $size = $sizeInfo ? $sizeInfo['cpu'] : null;
        if ($size === null) {
            return "";
        }

        if (!isset($sizeInfo['cpu_type'])) {
            return "";
        }

        return sprintf('(%s)', $sizeInfo['cpu_type']);
    }

    /**
     * Sort container profiles by size.
     *
     * @param array<string, array<string, array<string, string>>> $profiles
     *
     * @return array<string, array<string, array<string, string>>>
     */
    protected function sortContainerProfiles(array $profiles)
    {
        foreach ($profiles as &$profile) {
            uasort($profile, function ($a, $b) {
                return $a['cpu'] == $b['cpu'] ? 0 : ($a['cpu'] > $b['cpu'] ? 1 : -1);
            });
        }

        return $profiles;
    }
}
