<?php

namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Service\Config;
use Symfony\Contracts\Service\Attribute\Required;
use Platformsh\Cli\Command\CommandBase;

class SshKeyCommandBase extends CommandBase
{
    private readonly Config $config;
    #[Required]
    public function autowire(Config $config) : void
    {
        $this->config = $config;
    }
    /**
     * Returns a notice recommending SSH certificates instead of keys.
     *
     * @param bool $recommendCommand
     *
     * @return string
     */
    protected function certificateNotice($recommendCommand = true): string
    {
        $notice = '<fg=yellow;options=bold>Notice:</>'
            . "\n" . 'SSH keys are no longer needed by default, as SSH certificates are supported.'
            . "\n" . 'Certificates offer more security than keys.';
        if ($recommendCommand) {
            $notice .= "\n\n" . 'To load or check your SSH certificate, run: <info>'
                . $this->config->get('application.executable') . ' ssh-cert:load</info>';
        }
        return $notice;
    }
}
