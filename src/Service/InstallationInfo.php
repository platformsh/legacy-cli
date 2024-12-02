<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InstallationInfo
{
    private static ?array $otherPaths = null;

    private readonly OutputInterface $stdErr;

    public function __construct(
        private readonly Config $config,
        private readonly OsUtil $osUtil,
        OutputInterface         $output)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Returns whether other instances are installed of the CLI.
     *
     * Finds programs with the same executable name in the PATH.
     */
    public function otherCLIsInstalled(): bool
    {
        if (self::$otherPaths === null) {
            $thisPath = $this->cliPath();
            $paths = $this->osUtil->findExecutables($this->config->get('application.executable'));
            self::$otherPaths = array_unique(array_filter($paths, function ($p) use ($thisPath) {
                $realpath = realpath($p);
                return $realpath && $realpath !== $thisPath;
            }));
            if (!empty($otherPaths)) {
                $this->debug('Other CLI(s) found: ' . implode(", ", $otherPaths));
            }
        }
        return !empty($otherPaths);
    }

    private function cliPath(): string
    {
        $path = CLI_ROOT . '/bin/platform';
        if (defined('CLI_FILE')) {
            $path = CLI_FILE;
        }
        if (extension_loaded('Phar') && ($pharPath = \Phar::running(false))) {
            $path = $pharPath;
        }
        return $path;
    }

    /**
     * Prints a debug message.
     *
     * @todo deduplicate this
     *
     * @param string $message
     */
    private function debug(string $message): void
    {
        $this->stdErr->writeln('<options=reverse>DEBUG</> ' . $message, OutputInterface::VERBOSITY_DEBUG);
    }
}
