<?php

namespace CommerceGuys\Platform\Cli\Command;

class EnvironmentCommand extends PlatformCommand
{

    /**
     * @param string $operation
     * @param array|null $environment
     *
     * @return bool Whether the operation is allowed on the environment.
     */
    protected function operationAllowed($operation, $environment = null)
    {
        $environment = $environment ?: $this->environment;
        return $environment && isset($environment['_links']['#' . $operation]);
    }

    /**
     * Get the SSH URL for the selected environment.
     *
     * @throws \Exception
     *
     * @return string
     */
    protected function getSshUrl()
    {
        if (!$this->environment) {
            throw new \Exception("No environment selected");
        }

        if (!isset($this->environment['_links']['ssh']['href'])) {
            $id = $this->environment['id'];
            throw new \Exception("The environment $id does not have an SSH URL.");
        }

        $sshUrl = parse_url($this->environment['_links']['ssh']['href']);
        $host = $sshUrl['host'];
        $user = $sshUrl['user'];

        return $user . '@' . $host;
    }
}
