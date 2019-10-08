<?php
/**
 * @file
 * Service to help with mounts.
 */

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Model\AppConfig;

class Mount
{
    /**
     * Get a list of shared file mounts configured for an app.
     *
     * @param array $mounts An associative array of mounts, taken from the app
     *                      configuration.
     *
     * @return array
     *   An array of shared file paths, keyed by the mount path. Leading and
     *   trailing slashes are stripped. An empty shared path defaults to
     *   'files'.
     */
    public function getSharedFileMounts(array $mounts)
    {
        $sharedFileMounts = [];
        foreach ($this->normalizeMounts($mounts) as $path => $definition) {
            if (isset($definition['source_path'])) {
                $sharedFileMounts[$path] = $definition['source_path'] ?: 'files';
            }
        }

        return $sharedFileMounts;
    }

    /**
     * Find mounts in an application's config.
     *
     * @param AppConfig $appConfig
     *
     * @return array
     *   A normalized list of mounts.
     */
    public function mountsFromConfig(AppConfig $appConfig)
    {
        $config = $appConfig->getNormalized();
        if (empty($config['mounts'])) {
            return [];
        }

        return $this->normalizeMounts($config['mounts']);
    }

    /**
     * Normalize a list of mounts.
     *
     * This ensures the mount path does not begin or end with a '/', and that
     * the mount definition is in the newer structured format, with a 'source'
     * and probably a 'source_path'.
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
     * Checks that a given path matches a mount in the list.
     *
     * @param string $path
     * @param array  $mounts
     *
     * @throws \InvalidArgumentException if the path does not match
     *
     * @return string
     *   If the $path matches, the normalized path is returned.
     */
    public function matchMountPath($path, array $mounts)
    {
        $normalized = $this->normalizeRelativePath($path);
        if (isset($mounts[$normalized])) {
            return $normalized;
        }

        throw new \InvalidArgumentException(sprintf('Mount not found: <error>%s</error>', $path));
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
