<?php

declare(strict_types=1);

namespace Platformsh\Cli\Local\DependencyManager;

use Platformsh\Cli\Service\Shell;

abstract class DependencyManagerBase implements DependencyManagerInterface
{
    protected string $command = 'undefined';

    public function __construct(protected Shell $shell) {}

    /**
     * {@inheritdoc}
     */
    public function getCommandName(): string
    {
        return $this->command;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return $this->shell->commandExists($this->getCommandName());
    }

    /**
     * {@inheritdoc}
     */
    public function getEnvVars($path): array
    {
        return [];
    }

    protected function runCommand(string $command, ?string $path = null): void
    {
        $code = $this->shell->executeSimple($command, $path);
        if ($code > 0) {
            throw new \RuntimeException(sprintf(
                'The command failed with the exit code %d: %s',
                $code,
                $command,
            ));
        }
    }
}
