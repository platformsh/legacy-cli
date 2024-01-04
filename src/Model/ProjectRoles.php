<?php

namespace Platformsh\Cli\Model;

class ProjectRoles
{
    /**
     * Formats project-related permissions.
     *
     * @param string[] $permissions
     * @param bool $machineReadable
     *
     * @return string
     */
    public function formatPermissions(array $permissions, $machineReadable)
    {
        if (empty($permissions)) {
            return '';
        }
        if ($machineReadable) {
            return json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (in_array('admin', $permissions, true)) {
            return 'Project: admin';
        }
        $byType = ['production' => '', 'staging' => '', 'development' => ''];
        foreach ($permissions as $permission) {
            $parts = explode(':', $permission, 2);
            if (count($parts) === 2) {
                list($environmentType, $role) = $parts;
                $byType[$environmentType] = $role;
            }
        }
        $lines = [];
        if (in_array('viewer', $permissions, true)) {
            $lines[] = 'Project: viewer';
        }
        if ($byType = array_filter($byType)) {
            $lines[] = 'Environment types:';
            foreach ($byType as $envType => $role) {
                $lines[] = sprintf('- %s: %s', ucfirst($envType), $role);
            }
        }
        return implode("\n", $lines);
    }
}
