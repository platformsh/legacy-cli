<?php

namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Command\CommandBase;

class SshKeyCommandBase extends CommandBase
{
    /**
     * Returns a notice recommending SSH certificates instead of keys.
     *
     * @param bool $recommendCommand
     *
     * @return string
     */
    protected function certificateNotice($recommendCommand = true)
    {
        $notice = '<fg=yellow;options=bold>Notice:</>'
            . "\n" . 'SSH keys are no longer needed by default, as SSH certificates are supported.'
            . "\n" . 'Certificates offer more security than keys.';
        if ($recommendCommand) {
            $notice .= "\n\n" . 'To load or check your SSH certificate, run: <info>'
                . $this->config()->get('application.executable') . ' ssh-cert:load</info>';
        }
        return $notice;
    }
}
