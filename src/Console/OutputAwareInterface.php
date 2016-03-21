<?php
/**
 * @file
 * The output counterpart to Symfony Console's InputAwareInterface.
 */

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Output\OutputInterface;

interface OutputAwareInterface
{
    /**
     * Sets the Console output.
     *
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output);
}
