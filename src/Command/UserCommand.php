<?php

namespace Platformsh\Cli\Command;

abstract class UserCommand extends PlatformCommand
{

    /**
     * @param string $givenRole
     *
     * @return string
     * @throws \Exception
     */
    protected function standardizeRole($givenRole)
    {
        $possibleRoles = array('viewer', 'admin', 'contributor');
        if (in_array($givenRole, $possibleRoles)) {
            return $givenRole;
        }
        $role = strtolower($givenRole);
        foreach ($possibleRoles as $possibleRole) {
            if (strpos($possibleRole, $role) === 0) {
                return $possibleRole;
            }
        }
        throw new \Exception("Role not found: $givenRole");
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public function validateRole($value)
    {
        if (empty($value) || !in_array($value, array('admin', 'contributor', 'viewer', 'a', 'c', 'v'))) {
            throw new \RuntimeException("Invalid role: $value");
        }

        return $value;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public function validateEmail($value)
    {
        if (empty($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException("Invalid email address: $value");
        }

        return $value;
    }
}
