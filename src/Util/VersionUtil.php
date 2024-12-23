<?php

declare(strict_types=1);

namespace Platformsh\Cli\Util;

class VersionUtil
{
    /**
     * Finds the next patch, minor and major versions after a given version.
     *
     * @return string[]
     */
    public function nextVersions(string $version): array
    {
        if (preg_match('/^([0-9]+)\.([0-9]+)\.([0-9]+)(-.+)?$/', $version, $matches)) {
            $major = (int) $matches[1];
            $minor = (int) $matches[2];
            $patch = (int) $matches[3];
            $suffix = $matches[4] ?? '';
            $format = '%d.%d.%d%s';
            $nextPatch = \sprintf($format, $major, $minor, $patch + 1, $suffix);
            $nextMinor = \sprintf($format, $major, $minor + 1, 0, $suffix);
            $nextMajor = \sprintf($format, $major + 1, 0, 0, $suffix);
            return [$nextPatch, $nextMinor, $nextMajor];
        }
        return [];
    }


    /**
     * Checks if a version number is for a pre-release version.
     */
    public function isPreRelease(string $version): bool
    {
        return \preg_match('/^[0-9]+\.[0-9]+(\.[0-9]+)?-.+/', $version) === 1;
    }

    /**
     * @param string $version
     *
     * @return int
     */
    public function majorVersion(string $version): int
    {
        if ($dotPos = strpos($version, '.', 1)) {
            $major = substr($version, 0, $dotPos);
            if (is_numeric($major)) {
                return (int) $major;
            }
        } elseif (is_numeric($version)) {
            return (int) $version;
        }

        throw new \RuntimeException('Failed to find major version for: ' . $version);
    }
}
