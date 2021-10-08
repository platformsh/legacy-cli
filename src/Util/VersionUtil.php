<?php

namespace Platformsh\Cli\Util;

class VersionUtil
{
    /**
     * Finds the next patch, minor and major versions after a given version.
     *
     * @param string $version
     *
     * @return string[]
     */
    public function nextVersions($version)
    {
        if (preg_match('/^([0-9]+)\.([0-9]+)\.([0-9]+)(-.+)?$/', $version, $matches)) {
            $major = (int) $matches[1];
            $minor = (int) $matches[2];
            $patch = (int) $matches[3];
            $suffix = isset($matches[4]) ? $matches[4] : '';
            $format = '%d.%d.%d%s';
            $nextPatch = \sprintf($format, $major, $minor, $patch + 1, $suffix);
            $nextMinor = \sprintf($format, $major, $minor + 1, 0, $suffix);
            $nextMajor = \sprintf($format, $major + 1, 0, 0, $suffix);
            return [$nextPatch, $nextMinor, $nextMajor];
        }
        return [];
    }
}
