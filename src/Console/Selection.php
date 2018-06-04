<?php
declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;

class Selection
{
    private $environment;
    private $project;
    private $appName;

    public function __construct(Project $project = null, Environment $environment = null, $appName = null)
    {
        $this->project = $project;
        $this->environment = $environment;
        $this->appName = $appName;
    }

    /**
     * Check whether a project is selected.
     *
     * @return bool
     */
    public function hasProject()
    {
        return !empty($this->project);
    }

    /**
     * Get the project selected by the user.
     *
     * The project is selected via validateInput(), if there is a --project
     * option in the command.
     *
     * @throws \BadMethodCallException
     *
     * @return Project
     */
    public function getProject()
    {
        if (!$this->project) {
            throw new \BadMethodCallException('No project selected');
        }

        return $this->project;
    }

    /**
     * Check whether a single environment is selected.
     *
     * @return bool
     */
    public function hasEnvironment()
    {
        return !empty($this->environment);
    }

    /**
     * Get the environment selected by the user.
     *
     * The project is selected via validateInput(), if there is an
     * --environment option in the command.
     *
     * @return Environment
     */
    public function getEnvironment()
    {
        if (!$this->environment) {
            throw new \BadMethodCallException('No environment selected');
        }

        return $this->environment;
    }

    /**
     * Get the app selected by the user.
     *
     * @return string|null
     */
    public function getAppName()
    {
        return $this->appName;
    }
}
