<?php

declare(strict_types=1);

namespace Platformsh\Cli\Selector;

use Platformsh\Cli\Model\RemoteContainer\App;
use Platformsh\Cli\Model\RemoteContainer\RemoteContainerInterface;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;

class Selection
{
    public readonly SelectorConfig $config;

    public function __construct(
        ?SelectorConfig           $config = null,
        private readonly ?Project                  $project = null,
        private readonly ?Environment              $environment = null,
        private ?string                            $appName = null,
        private readonly ?RemoteContainerInterface $remoteContainer = null,
    ) {
        $this->config = $config ?: new SelectorConfig();
    }

    /**
     * Check whether a project is selected.
     *
     * @return bool
     */
    public function hasProject(): bool
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
    public function getProject(): Project
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
    public function hasEnvironment(): bool
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
    public function getEnvironment(): Environment
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
    public function getAppName(): ?string
    {
        if ($this->appName === null && $this->remoteContainer instanceof App) {
            $this->appName = $this->remoteContainer->getName();
        }

        return $this->appName;
    }

    /**
     * Get the remote container selected by the user.
     */
    public function getRemoteContainer(): RemoteContainerInterface
    {
        if (!$this->remoteContainer) {
            throw new \BadMethodCallException('No container selected');
        }

        return $this->remoteContainer;
    }
}
