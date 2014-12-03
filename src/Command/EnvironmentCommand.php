<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;

class EnvironmentCommand extends PlatformCommand
{

    /**
     * @param string $operation
     * @param array|null $environment
     *
     * @return bool Whether the operation is allowed on the environment.
     */
    protected function operationAllowed($operation, array $environment = null)
    {
        $data = $environment ?: $this->environment;
        if (!$data) {
            return false;
        }
        $wrapper = new Environment($data);
        $result = $wrapper->operationAllowed($operation);

        // Refresh the environment to work around caching issues.
        // @todo remove this when HTTP caching is enabled
        if (!$result && $environment === null) {
            $data = $this->getEnvironment($wrapper->id(), null, true);
            if (!$data) {
                throw new \RuntimeException("Environment not found: " . $wrapper->id());
            }
            $wrapper = new Environment($data);
            return $wrapper->operationAllowed($operation);
        }

        return $result;
    }

}
