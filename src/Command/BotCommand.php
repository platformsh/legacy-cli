<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\Bot;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BotCommand extends PlatformCommand
{
    protected $hiddenInList = true;
    protected $local = true;

    protected function configure()
    {
        $this->setName('bot')->setDescription('The Platform.sh Bot');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $bot = new Bot($this->stdErr);
        while (true) {
            $bot->displayNext();
        }
    }
}
