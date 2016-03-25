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

        $indent = '    ';
        $signatureIndent = str_repeat(' ', strlen($indent) + 6 - ceil(strlen(CLI_CLOUD_SERVICE) / 2));
        $signature = "\n" . $signatureIndent . '<info>' . CLI_CLOUD_SERVICE . '</info>';

        // The frames are the contents of each file, with each line indented.
        $frames = array_map(function ($filename) use ($indent, $signature) {
            return preg_replace('/^/m', $indent, file_get_contents($filename))
                . $signature;
        }, $filenames);

        parent::__construct($output, $frames);
    }
}
