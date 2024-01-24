<?php

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Input\InputOption;

class OrganizationUserCommandBase extends OrganizationCommandBase implements CompletionAwareInterface
{
    // @todo add 'admin'
    protected static $allPermissions = ['billing', 'members', 'plans', 'projects:create', 'projects:list'];

    /**
     * Returns a list of permissions formatted for display.
     *
     * @param string[]|null $permissions
     *   The permissions. If null, all permissions will be used.
     *
     * @return string
     */
    protected function listPermissions($permissions = null)
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
    protected function addPermissionOption()
    {
        return $this->addOption(
            'permission',
            null,
            InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY,
            'Permission(s) for the user on the organization. '
            . "\n" . 'Valid permissions are: <info>' . implode('</info>, <info>', self::$allPermissions) . '</info>'
        );
    }

    public function completeOptionValues($optionName, CompletionContext $context)
    {
        if ($optionName === 'permission') {
            return self::$allPermissions;
        }
        return [];
    }

    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        return [];
    }
}
