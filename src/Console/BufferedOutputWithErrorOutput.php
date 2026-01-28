<?php

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A Symfony Console output class implementing a buffer for stdout and another output class for stderr.
 */
class BufferedOutputWithErrorOutput extends BufferedOutput implements ConsoleOutputInterface
{
    private $stdErr;

    public function __construct(OutputInterface $errorOutput)
    {
        parent::__construct();
        $this->setErrorOutput($errorOutput);
    }

    public function getErrorOutput()
    {
        return $this->stdErr;
    }

    public function setErrorOutput(OutputInterface $error)
    {
        $this->stdErr = $error;
    }
}
