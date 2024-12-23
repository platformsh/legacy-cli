<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\CommandBase;

class SshKeyCommandBase extends CommandBase
{
    /**
     * Returns a notice recommending SSH certificates instead of keys.
     */
    protected function certificateNotice(Config $config, bool $recommendCommand = true): string
    {
        $notice = '<fg=yellow;options=bold>Notice:</>'
            . "\n" . 'SSH keys are no longer needed by default, as SSH certificates are supported.'
            . "\n" . 'Certificates offer more security than keys.';
        if ($recommendCommand) {
            $notice .= "\n\n" . 'To load or check your SSH certificate, run: <info>'
                . $config->getStr('application.executable') . ' ssh-cert:load</info>';
        }
        return $notice;
    }
}
