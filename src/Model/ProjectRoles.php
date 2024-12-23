<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model;

class ProjectRoles
{
    /**
     * Formats project-related permissions.
     *
     * @param string[] $permissions
     * @throws \JsonException
     */
    public function formatPermissions(array $permissions, bool $machineReadable): string
    {
        if (empty($permissions)) {
            return '';
        }
        if ($machineReadable) {
            return json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if (in_array('admin', $permissions, true)) {
            return 'Project: admin';
        }
        $byType = ['production' => '', 'staging' => '', 'development' => ''];
        foreach ($permissions as $permission) {
            $parts = explode(':', $permission, 2);
            if (count($parts) === 2) {
                [$environmentType, $role] = $parts;
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
