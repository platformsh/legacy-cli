<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Client\Model\Environment;

readonly class HostFactory
{
    public function __construct(private Shell $shell, private Ssh $ssh, private SshDiagnostics $sshDiagnostics) {}

    public function local(): LocalHost
    {
        return new LocalHost($this->shell);
    }

    public function remote(string $sshUrl, Environment $environment): RemoteHost
    {
        return new RemoteHost($sshUrl, $environment, $this->ssh, $this->shell, $this->sshDiagnostics);
    }
}
