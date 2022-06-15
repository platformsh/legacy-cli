<?php declare(strict_types=1);

namespace Platformsh\Cli\Tests;

use Symfony\Component\Process\Process;

class CommandRunner
{
    /**
     * @param string   $commandName
     * @param string[] $args
     * @param array    $env
     * @param bool     $allowFailure
     *
     * @return \Platformsh\Cli\Tests\CommandResult
     */
    public function run(string $commandName, array $args = [], array $env = [], bool $allowFailure = false): CommandResult {
        $path = realpath(__DIR__ . '/../bin/platform');
        if (!$path) {
            throw new \RuntimeException('Cannot find executable');
        }
        $args = array_merge([$path, $commandName], $args);
        $process = new Process($args, null, $env + \getenv());
        $process->run();
        $exitCode = $process->getExitCode();
        if (!$allowFailure && $exitCode !== 0) {
            throw new \RuntimeException(
                "Command failed with exit code $exitCode: {$process->getCommandLine()}"
                . "\nOutput: {$process->getOutput()}"
                . "\nError output: {$process->getErrorOutput()}"
            );
        }

        return new CommandResult($exitCode, $process->getOutput(), $process->getErrorOutput());
    }
}
