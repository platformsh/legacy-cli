<?php

namespace Platformsh\Cli\Util;

use Symfony\Component\Console\Output\OutputInterface;

class Bot extends ConsoleAnimation
{
    /**
     * @param OutputInterface $output
     */
    public function __construct(OutputInterface $output)
    {
        $filenames = [
            CLI_ROOT . '/resources/bot/bot1',
            CLI_ROOT . '/resources/bot/bot2',
            CLI_ROOT . '/resources/bot/bot3',
            CLI_ROOT . '/resources/bot/bot4',
        ];

        // The frames are the contents of each file, with each line indented.
        $frames = array_map(function ($filename) {
            return preg_replace('/^/m', '    ', file_get_contents($filename))
                . "\n    <info>" . CLI_CLOUD_SERVICE . "</info>";
        }, $filenames);

        parent::__construct($output, $frames);
    }
}
