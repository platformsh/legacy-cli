<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Client\Model\Environment;

class HostFactory {
    public function __construct(private readonly Shell $shell, private readonly Ssh $ssh, private readonly SshDiagnostics $sshDiagnostics)
    {
    }

    public function local(): LocalHost {
        return new LocalHost($this->shell);
    }

    public function remote(string $sshUrl, Environment $environment): RemoteHost {
        return new RemoteHost($sshUrl, $environment, $this->ssh, $this->shell, $this->sshDiagnostics);
    }
}
