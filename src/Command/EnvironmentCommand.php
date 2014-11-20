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
        $environment = new Environment($data);
        return $environment->operationAllowed($operation);
    }

}
