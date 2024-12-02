<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

readonly class IO
{
    private OutputInterface $stdErr;

    public function __construct(OutputInterface $output)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    public function debug(string $message): void
    {
        if ($this->stdErr->isDebug()) {
            $this->stdErr->writeln('<options=reverse>DEBUG</> ' . $message);
        }
    }
}
