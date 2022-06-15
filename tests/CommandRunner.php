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
     * @return CommandResult
     */
    public function run(string $commandName, array $args = [], array $env = [], bool $allowFailure = false): CommandResult {
        $path = realpath(__DIR__ . '/../bin/platform');
        if (!$path) {
            throw new \RuntimeException('Cannot find executable');
        }
        $args = array_merge([$path, $commandName], $args);

        // Ensure the CLI is not logged in during the test.
        $env += [
            'PLATFORMSH_CLI_NO_INTERACTION' => '1',
            'PLATFORMSH_CLI_SESSION_ID' => 'test' . rand(100, 999),
            'PLATFORMSH_CLI_TOKEN' => '',
        ] + \getenv();

        $process = new Process($args, null, $env);
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
