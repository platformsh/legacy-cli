<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalProject;


class Fleets
{

    const FLEET_DOES_NOT_EXIST = 0;
    const FLEET_ALREADY_EXISTS = 1;
    const FLEET_ADDED = 2;
    const FLEET_REMOVED = 3;

    const PROJECT_DOES_NOT_EXIST = 0;
    const PROJECT_ALREADY_EXISTS  = 1;
    const PROJECT_ADDED = 2;
    const PROJECT_REMOVED = 3;

    protected $fleetConfig;

    protected $localProject;
    protected $config;
    protected $filesystem;

    /**
     * Fleets constructor.
     */
    public function __construct()
    {
        $this->localProject = new LocalProject();
        $this->config = new Config();
        $this->filesystem = new Filesystem();
    }

    /**
     * Get current fleet configuration for this project.
     *
     * @return array
     */
    public function getFleetConfiguration()
    {

        $projectRoot = $this->localProject->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $configFileName = $this->getConfigFileName();

        if ($this->filesystem->fileExists($configFileName)) {
            $this->fleetConfig = $this->filesystem->readYamlFile($configFileName);
        }

        // If the config was empty (e.g. the YAML file is blank) or not set at all,
        // initialise it.
        if (!isset($this->fleetConfig) || empty($this->fleetConfig)) {
            $this->fleetConfig = $this->defaultFleets();
        }

        return $this->fleetConfig;
    }


    /**
     * Add a fleet.
     *
     * @param $fleetName
     * @return bool
     */
    public function addFleet($fleetName) {
        if (empty($this->fleetConfig)) {
            $this->getFleetConfiguration();
        }

        if (array_key_exists($fleetName, $this->fleetConfig['fleets'])) {
            return self::FLEET_ALREADY_EXISTS;
        }

        $this->fleetConfig['fleets'][$fleetName] = $this->defaultFleet();

        $this->saveFleetsConfiguration();

        return self::FLEET_ADDED;
    }

    /**
     * @param $fleetName
     * @return bool
     */
    public function removeFleet($fleetName) {
        if (empty($this->fleetConfig)) {
            $this->getFleetConfiguration();
        }

        if (!array_key_exists($fleetName, $this->fleetConfig['fleets'])) {
            return self::FLEET_DOES_NOT_EXIST;
        }

        unset($this->fleetConfig['fleets'][$fleetName]);

        $this->saveFleetsConfiguration();

        return self::FLEET_REMOVED;
    }

    /**
     * @param $fleetName
     * @param $projectID
     *
     * @return bool
     */
    public function addProject($fleetName, $projectID) {
        $this->getFleetConfiguration();
        if (empty($this->fleetConfig['fleets']) || !array_key_exists($fleetName, $this->fleetConfig['fleets'])) {
            // No fleets are set.
            $this->fleetConfig['fleets'][$fleetName] = $this->defaultFleet();
        }

        $this->fleetConfig['fleets'][$fleetName]['projects'][$projectID] = TRUE;

        $this->saveFleetsConfiguration();

        return TRUE;
    }

    /**
     * @return string
     */
    protected function getConfigFileName()
    {
        return $this->config->get('service.project_config_dir') . '/fleets.yaml';
    }

    /**
     *
     */
    protected function saveFleetsConfiguration()
    {
        if ($this->filesystem->fileExists($this->getConfigFileName())) {
            $this->filesystem->remove($this->getConfigFileName());
        }

        $this->filesystem->createYamlFile($this->getConfigFileName(), $this->fleetConfig);
    }

    /**
     * @return array
     */
    protected function defaultFleets() {
        return [
            'fleets' => [],
        ];
    }

    /**
     * @return array
     */
    protected function defaultFleet() {
        return [
            'projects' => [],
        ];
    }
}
