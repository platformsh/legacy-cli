<?php

declare(strict_types=1);

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
     * @param array<string, mixed> $mounts
     *   Mounts, from the app configuration.
     *
     * @return array<string, string>
     *   An array of shared file paths, keyed by the mount path. Leading and
     *   trailing slashes are stripped. An empty shared path defaults to
     *   'files'.
     */
    public function getSharedFileMounts(array $mounts): array
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
     * @return array<string, array{source: string, source_path?: string}>
     *   A normalized list of mounts.
     */
    public function mountsFromConfig(AppConfig $appConfig): array
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
     * @param array<string, mixed> $mounts
     *
     * @return array<string, array{source: string, source_path?: string}>
     */
    public function normalizeMounts(array $mounts): array
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
     * @param array<string, mixed> $mounts
     *
     * @return string
     *   If the $path matches, the normalized path is returned.
     *@throws \InvalidArgumentException if the path does not match
     *
     */
    public function matchMountPath(string $path, array $mounts): string
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
    private function normalizeRelativePath(string $path): string
    {
        return trim(trim($path), '/');
    }

    /**
     * Normalizes a mount definition.
     *
     * @param array{source?: string, source_path?: string}|string $definition
     *
     * @return array{source: string, source_path?: string}
     */
    private function normalizeDefinition(array|string $definition): array
    {
        if (!is_array($definition)) {
            if (!str_contains($definition, 'shared:files')) {
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
