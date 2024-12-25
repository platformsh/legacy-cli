<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\Animation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bot', description: 'The Platform.sh Bot')]
class BotCommand extends CommandBase
{
    protected bool $hiddenInList = true;
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('party', null, InputOption::VALUE_NONE)
            ->addOption('parrot', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = CLI_ROOT . '/resources/bot';
        $signature = $this->config->getStr('service.name');
        $party = $input->getOption('party');
        $interval = $party ? 120000 : 500000;

        // With thanks to https://github.com/jmhobbs/terminal-parrot
        if ($input->getOption('parrot')) {
            $dir = dirname($dir) . '/parrot';
            $interval = $party ? 70000 : 100000;
            $signature = '';
        }

        $files = scandir($dir);
        if (!$files) {
            throw new \RuntimeException('Failed to read directory: ' . $dir);
        }

        $frames = [];
        foreach ($files as $filename) {
            if ($filename[0] !== '.') {
                $frames[] = (string) file_get_contents($dir . '/' . $filename);
            }
        }

        if ($signature) {
            $frames = $this->addSignature($frames, $signature);
        }

        if ($party) {
            $frames = $this->addColor($frames);
        }

        $animation = new Animation($output, $frames, $interval);

        if (!$output->isDecorated()) {
            $animation->render();
            return 0;
        }

        // Stay positive: return code 0 when the user quits.
        if (function_exists('pcntl_signal')) {
            declare(ticks=1);
            pcntl_signal(SIGINT, function (): void {
                echo "\n";
                exit;
            });
        }

        while (true) {
            $animation->render();
        }
    }

    /**
     * @param array<string|\Stringable> $frames
     * @param string $signature
     * @return string[]
     */
    private function addSignature(array $frames, string $signature): array
    {
        $indent = '    ';
        if (strlen($signature) > 0) {
            $signatureIndent = str_repeat(' ', (int) (strlen($indent) + 5 - floor(strlen($signature) / 2)));
            $signature = "\n" . $signatureIndent . '<info>' . $signature . '</info>';
        }

        return array_map(fn($frame) => preg_replace('/^/m', $indent, (string) $frame) . $signature, $frames);
    }

    /**
     * @param string[] $frames
     * @return string[]
     */
    private function addColor(array $frames): array
    {
        $colors = ['red', 'yellow', 'green', 'blue', 'magenta', 'cyan', 'white'];
        $partyFrames = [];
        for ($i = 1; $i <= 7; $i++) {
            foreach ($frames as $frame) {
                shuffle($colors);
                $color = reset($colors);
                $partyFrames[] = "<fg=$color>$frame</>";
            }
        }

        return $partyFrames;
    }
}
