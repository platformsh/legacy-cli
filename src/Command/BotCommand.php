<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\Animation;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BotCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $local = true;

    protected function configure()
    {
        $this->setName('bot')
            ->setDescription('The ' . $this->config()->get('service.name') . ' Bot')
            ->addOption('party', null, InputOption::VALUE_NONE)
            ->addOption('parrot', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dir = CLI_ROOT . '/resources/bot';
        $signature = $this->config()->get('service.name');
        $party = $input->getOption('party');
        $interval = $party ? 120000 : 500000;

        // With thanks to https://github.com/jmhobbs/terminal-parrot
        if ($input->getOption('parrot')) {
            $dir = dirname($dir) . '/parrot';
            $interval = $party ? 70000 : 100000;
            $signature = '';
        }

        $frames = [];
        foreach (scandir($dir) as $filename) {
            if ($filename[0] !== '.') {
                $frames[] = file_get_contents($dir . '/' . $filename);
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
            return;
        }

        // Stay positive: return code 0 when the user quits.
        if (function_exists('pcntl_signal')) {
            declare(ticks = 1);
            /** @noinspection PhpComposerExtensionStubsInspection */
            pcntl_signal(SIGINT, function () {
                echo "\n";
                exit;
            });
        }

        while (true) {
            $animation->render();
        }
    }

    private function addSignature(array $frames, $signature)
    {
        $indent = '    ';
        if (strlen($signature) > 0) {
            $signatureIndent = str_repeat(' ', strlen($indent) + 5 - floor(strlen($signature) / 2));
            $signature = "\n" . $signatureIndent . '<info>' . $signature . '</info>';
        }

        return array_map(function ($frame) use ($indent, $signature) {
            return preg_replace('/^/m', $indent, $frame) . $signature;
        }, $frames);
    }

    private function addColor(array $frames)
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
