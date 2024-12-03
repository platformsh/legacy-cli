<?php

namespace Platformsh\Cli\Selector;

use Platformsh\Client\Model\Environment;

class SelectorConfig
{
    public function __construct(
        public bool $envRequired = true,
        public string $envArgName = 'environment',
        public string $chooseProjectText = 'Enter a number to choose a project:',
        public string $chooseEnvText = 'Enter a number to choose an environment:',
        public string $enterProjectText = 'Enter a project ID',
        public string $enterEnvText = 'Enter an environment ID',
        public bool $selectDefaultEnv = false,
        public bool $detectCurrentEnv = true,
        public bool $allowLocalHost = false,
        public bool $requireApiOnLocal = false,
        /** @var callable|null */
        public mixed $chooseEnvFilter = null,
    ) {
    }

    /**
     * Returns an environment filter to select environments that may be active.
     *
     * @return callable
     */
    public static function filterEnvsMaybeActive(): callable
    {
        return fn(Environment $e): bool => \in_array($e->status, ['active', 'dirty'], true) || count($e->getSshUrls()) > 0;
    }
}