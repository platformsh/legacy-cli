<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Client\Model\Environment;

class HostFactory {
    private Shell $shell;
    private Ssh $ssh;
    private SshDiagnostics $sshDiagnostics;

    public function __construct(Shell $shell, Ssh $ssh, SshDiagnostics $sshDiagnostics) {
        $this->shell = $shell;
        $this->ssh = $ssh;
        $this->sshDiagnostics = $sshDiagnostics;
    }

    public function local(): LocalHost {
        return new LocalHost($this->shell);
    }

    public function remote(string $sshUrl, Environment $environment): RemoteHost {
        return new RemoteHost($sshUrl, $environment, $this->ssh, $this->shell, $this->sshDiagnostics);
    }
}
