<?php

namespace Platformsh\Cli\Command\Helper;

use Platformsh\Cli\Command\CommandBase;

abstract class HelperCommandBase extends CommandBase
{
    protected $stability = 'alpha';

    /**
     * Get an environment variable.
     *
     * @todo support doing this remotely too
     *
     * @param string $name
     *
     * @return string
     */
    protected function getEnvVar($name) {
        $envVarName = $this->config()->get('service.env_prefix') . $name;

        $value = getenv($envVarName);
        if ($value === false) {
            throw new \InvalidArgumentException('Environment variable not found: ' . $envVarName);
        }

        return $value;
    }

    /**
     * @param string $name
     *
     * @return array
     */
    protected function getArrayEnvVar($name) {
        $value = $this->getEnvVar($name);

        try {
            $result = $this->decodeToArray($value);
        } catch (\RuntimeException $e) {
            $envVarName = $this->config()->get('service.env_prefix') . $name;
            throw new \RuntimeException('Failed to decode variable ' . $envVarName . ': ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Safely decode base64-encoded JSON to an array.
     *
     * @param string $value
     *
     * @return array
     */
    private function decodeToArray($value) {
        $json = base64_decode($value, true);
        if ($json === false) {
            throw new \RuntimeException('The value is not valid base64');
        }
        if (defined('JSON_THROW_ON_ERROR')) {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } else {
            $decoded = json_decode($json, true);
            $error = json_last_error();
            if ($error !== JSON_ERROR_NONE) {
                throw new \RuntimeException('JSON error: ' . json_last_error_msg());
            }
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('The value is not an array or object');
        }

        return $decoded;
    }
}
