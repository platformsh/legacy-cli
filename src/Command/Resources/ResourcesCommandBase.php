<?php

namespace Platformsh\Cli\Command\Resources;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Platformsh\Client\Model\Deployment\Service;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Deployment\Worker;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputInterface;

class ResourcesCommandBase extends CommandBase
{
    private static $cachedNextDeployment = [];

    public function isHidden()
    {
        return !$this->config()->get('api.sizing') || parent::isHidden();
    }

    /**
     * Lists services in a deployment.
     *
     * @param EnvironmentDeployment $deployment
     *
     * @return array<string, WebApp||Worker|Service>
     *     An array of services keyed by the service name.
     */
    protected function allServices(EnvironmentDeployment $deployment)
    {
        $webapps = $deployment->webapps;
        $workers = $deployment->workers;
        $services = $deployment->services;
        ksort($webapps, SORT_STRING|SORT_FLAG_CASE);
        ksort($workers, SORT_STRING|SORT_FLAG_CASE);
        ksort($services, SORT_STRING|SORT_FLAG_CASE);
        return array_merge($webapps, $workers, $services);
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
     * Loads the next environment deployment and caches it statically.
     *
     * The static cache means it can be reused while running a sub-command.
     *
     * @param Environment $environment
     * @param bool $reset
     * @return EnvironmentDeployment
     */
    protected function loadNextDeployment(Environment $environment, $reset = false)
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
                list($prefix) = explode(':', $service->type, 2);
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
     * @param array $properties
     *   The service properties (e.g. from $service->getProperties()).
     * @param array $containerProfiles
     *   The list of container profiles (e.g. from
     *   $deployment->container_profiles).
     *
     * @return array{'cpu': string, 'memory': string}|null
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
}
