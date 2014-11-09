<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;

class ShellHelper extends Helper {

    public function getName()
    {
        return 'shell';
    }

    /**
     * Run a shell command in the current directory, suppressing errors.
     *
     * @param string $cmd The command, suitably escaped.
     * @param string &$error Optionally use this to capture errors.
     *
     * @throws \Exception
     *
     * @return string The command output.
     */
    public function execute($cmd, &$error = '')
    {
        $descriptorSpec = array(
          0 => array('pipe', 'r'), // stdin
          1 => array('pipe', 'w'), // stdout
          2 => array('pipe', 'w'), // stderr
        );
        $process = proc_open($cmd, $descriptorSpec, $pipes);
        if (!$process) {
            throw new \Exception('Failed to execute command');
        }
        $result = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $result;
    }
}
