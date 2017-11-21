<?php
/**
 * @file
 * Service to help with mounts.
 */

namespace Platformsh\Cli\Service;

class Mount
{
    /**
     * Get a list of shared file mounts configured for an app.
     *
     * @param array $appConfig The app configuration.
     *
     * @return array
     *   An array of shared file paths, keyed by the mount path. Leading and
     *   trailing slashes are stripped. An empty shared path defaults to
     *   'files'.
     */
    public function getSharedFileMounts(array $appConfig)
    {
        $sharedFileMounts = [];
        if (!empty($appConfig['mounts'])) {
            foreach ($this->normalizeMounts($appConfig['mounts']) as $path => $definition) {
                if (isset($definition['source_path'])) {
                    $sharedFileMounts[$path] = $definition['source_path'] ?: 'files';
                }
            }
        }

        return $sharedFileMounts;
    }

    /**
     * Normalize a list of mounts.
     *
     * @param array $mounts
     *
     * @return array
     */
    public function normalizeMounts(array $mounts)
    {
        $normalized = [];
        foreach ($mounts as $path => $definition) {
            $normalized[$this->normalizeRelativePath($path)] = $this->normalizeDefinition($definition);
        }

        return $normalized;
    }

    /**
     * Validate and normalize a path to a mount.
     *
     * @param string $inputPath
     * @param array  $mounts
     *
     * @return string
     *   The normalized mount path.
     */
    public function validateMountPath($inputPath, array $mounts)
    {
        $normalized = $this->normalizeRelativePath($inputPath);
        if (isset($mounts[$normalized])) {
            return $normalized;
        }

        throw new \InvalidArgumentException(sprintf('Mount not found: <error>%s</error>', $inputPath));
    }

    /**
     * Normalize a path to a mount.
     *
     * @param string $path
     *
     * @return string
     */
    private function normalizeRelativePath($path)
    {
        return trim(trim($path), '/');
    }

    /**
     * Normalize a mount definition.
     *
     * @param array|string $definition
     *
     * @return array
     *   An array containing at least 'source', and probably 'source_path'.
     */
    private function normalizeDefinition($definition)
    {
        if (!is_array($definition)) {
            if (!is_string($definition) || strpos($definition, 'shared:files') === false) {
                throw new \RuntimeException('Failed to parse mount definition: ' . json_encode($definition));
            }
            $definition = [
                'source' => 'local',
                'source_path' => str_replace('shared:files', '', $definition),
            ];
        } elseif (!isset($definition['source'])) {
            throw new \InvalidArgumentException('Invalid mount definition: ' . json_encode($definition));
        }
        if (isset($definition['source_path'])) {
            $definition['source_path'] = $this->normalizeRelativePath($definition['source_path']);
        }

        return $definition;
    }
}
