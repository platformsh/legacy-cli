<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization\User;

use Symfony\Component\Console\Command\Command;
use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Symfony\Component\Console\Input\InputOption;

class OrganizationUserCommandBase extends OrganizationCommandBase
{
    // @todo add 'admin'
    /** @var string[] */
    protected static array $allPermissions = ['billing', 'members', 'plans', 'projects:create', 'projects:list'];

    /**
     * Returns a list of permissions formatted for display.
     *
     * @param string[]|null $permissions
     *   The permissions. If null, all permissions will be used.
     *
     * @return string
     */
    protected function listPermissions(?array $permissions = null): string
    {
        if ($permissions === []) {
            return '<info>[none]</info>';
        }
        return '<info>' . implode('</info>, <info>', $permissions ?: self::$allPermissions) . '</info>';
    }

    /**
     * Adds a --permission option.
     *
     * @return $this
     */
    protected function addPermissionOption(): Command
    {
        return $this->addOption(
            'permission',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Permission(s) for the user on the organization. '
            . "\n" . 'Valid permissions are: <info>' . implode('</info>, <info>', self::$allPermissions) . '</info>',
            null,
            self::$allPermissions,
        );
    }
}
