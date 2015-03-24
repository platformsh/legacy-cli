<?php

namespace Platformsh\Cli\Util;

use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Output\OutputInterface;

class CustomConsoleLogger extends AbstractLogger
{

    /** @var OutputInterface */
    protected $output;

    /**
     * @param OutputInterface $output
     */
    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * @inheritdoc
     */
    public function log($level, $message, array $context = array())
    {
        $this->output->writeln($message);
    }
}
