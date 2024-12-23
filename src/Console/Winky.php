<?php

declare(strict_types=1);

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
        $sources['normal'] = (string) file_get_contents($dir . '/normal');
        $sources['wink'] = (string) file_get_contents($dir . '/wink');
        $sources['twitch'] = (string) file_get_contents($dir . '/twitch');

        [$firstLine, ] = explode("\n", trim($sources['normal']), 2);
        $width = mb_strlen($firstLine);

        // Replace Unicode characters with ANSI background colors.
        if ($output->isDecorated()) {
            foreach ($sources as &$source) {
                $source = preg_replace_callback('/([\x{2588}\x{2591} ])\1*/u', function (array $matches): string {
                    $styles = [
                        ' ' => "\033[47m",
                        '█' => "\033[40m",
                        '░' => "\033[48;5;217m",
                    ];
                    $char = mb_substr((string) $matches[0], 0, 1);

                    return $styles[$char] . str_repeat(' ', mb_strlen((string) $matches[0])) . "\033[0m";
                }, $source);
            }
        }

        // Add the indent and signature.
        $indent = '      ';
        if (strlen($signature) > 0) {
            $signatureIndent = str_repeat(' ', intval(strlen($indent) + floor($width / 2) - floor(strlen($signature) / 2)));
            $signature = "\n" . $signatureIndent . $signature;
        }
        $sources = array_map(fn($source): string => "\n" . preg_replace('/^/m', $indent, (string) $source) . $signature . "\n", $sources);

        $frames = [];
        $frames[] = new AnimationFrame($sources['normal'], 1200000);
        $frames[] = new AnimationFrame($sources['wink'], 200000);
        $frames[] = new AnimationFrame($sources['normal'], 1200000);
        $frames[] = new AnimationFrame($sources['twitch'], 150000);

        parent::__construct($output, $frames);
    }
}
