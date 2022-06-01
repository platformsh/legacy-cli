<?php

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Output\OutputInterface;

class Winky extends Animation
{
    /**
     * @param OutputInterface $output
     * @param string          $signature
     */
    public function __construct(OutputInterface $output, $signature = '')
    {
        $dir = CLI_ROOT . '/resources/winky';

        $sources = [];
        $sources['normal'] = file_get_contents($dir . '/normal');
        $sources['wink'] = file_get_contents($dir . '/wink');
        $sources['twitch'] = file_get_contents($dir . '/twitch');

        list($firstLine,) = explode("\n", trim($sources['normal']), 2);
        $width = mb_strlen($firstLine);

        // Replace Unicode characters with ANSI background colors.
        if ($output->isDecorated()) {
            foreach ($sources as &$source) {
                $source = preg_replace_callback('/([\x{2588}\x{2591} ])\1*/u', function (array $matches) {
                    $styles = [
                        ' ' => "\033[47m",
                        '█' => "\033[40m",
                        '░' => "\033[48;5;217m",
                    ];
                    $char = mb_substr($matches[0], 0, 1);

                    return $styles[$char] . str_repeat(' ', mb_strlen($matches[0])) . "\033[0m";
                }, $source);
            }
        }

        // Add the indent and signature.
        $indent = '      ';
        if (strlen($signature) > 0) {
            $signatureIndent = str_repeat(' ', strlen($indent) + floor($width / 2) - floor(strlen($signature) / 2));
            $signature = "\n" . $signatureIndent . $signature;
        }
        $sources = array_map(function ($source) use ($indent, $signature) {
            return "\n" . preg_replace('/^/m', $indent, $source) . $signature . "\n";
        }, $sources);

        $frames = [];
        $frames[] = new AnimationFrame($sources['normal'], 1200000);
        $frames[] = new AnimationFrame($sources['wink'], 200000);
        $frames[] = new AnimationFrame($sources['normal'], 1200000);
        $frames[] = new AnimationFrame($sources['twitch'], 150000);

        parent::__construct($output, $frames);
    }
}
