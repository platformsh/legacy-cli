<?php

namespace Platformsh\Cli\Model\Host;

use Platformsh\Cli\Service\Shell;
use Symfony\Component\Console\Input\InputInterface;

class LocalHost implements HostInterface
{
    private $shell;

    public function __construct(Shell $shell = null)
    {
        $this->shell = $shell ?: new Shell();
    }

    /**
     * {@inheritDoc}
     */
    public function getLabel()
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
    public static function conflictsWithCommandLineOptions(InputInterface $input, $envPrefix)
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
    public function getCacheKey()
    {
        return 'localhost';
    }

    /**
     * {@inheritDoc}
     */
    public function runCommand($command, $mustRun = true, $quiet = true, $input = null)
    {
        return $this->shell->execute($command, null, $mustRun, $quiet, [], 3600, $input);
    }

    /**
     * {@inheritDoc}
     */
    public function runCommandDirect($commandLine, $append = '')
    {
        return $this->shell->executeSimple($commandLine . $append);
    }
}
