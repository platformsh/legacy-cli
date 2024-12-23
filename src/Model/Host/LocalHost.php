<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\Host;

use Platformsh\Cli\Service\Shell;
use Symfony\Component\Console\Input\InputInterface;

readonly class LocalHost implements HostInterface
{
    private Shell $shell;

    public function __construct(?Shell $shell = null)
    {
        $this->shell = $shell ?: new Shell();
    }

    /**
     * {@inheritDoc}
     */
    public function getLabel(): string
    {
        return 'localhost';
    }

    /**
     * Returns whether command-line options are asking for another host.
     *
     * @param InputInterface $input
     * @param string $envPrefix
     *
     * @return bool True if there is a conflict, or false if the local host can
     *              be safely used.
     */
    public static function conflictsWithCommandLineOptions(InputInterface $input, string $envPrefix): bool
    {
        $map = [
            'PROJECT' => 'project',
            'BRANCH' => 'environment',
            'APPLICATION_NAME' => 'app',
        ];
        foreach ($map as $varName => $optionName) {
            if ($input->hasOption($optionName)
                && $input->getOption($optionName) !== null
                && getenv($envPrefix . $varName) !== $input->getOption($optionName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getCacheKey(): string
    {
        return 'localhost';
    }

    public function lastChanged(): string
    {
        return '';
    }

    public function runCommand(string $command, bool $mustRun = true, bool $quiet = true, mixed $input = null): false|string
    {
        return $this->shell->execute($command, mustRun: $mustRun, quiet: $quiet, input: $input);
    }

    public function runCommandDirect(string $commandLine, string $append = ''): int
    {
        return $this->shell->executeSimple($commandLine . $append);
    }
}
