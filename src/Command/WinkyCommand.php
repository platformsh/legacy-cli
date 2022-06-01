<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\Winky;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WinkyCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $local = true;

    protected function configure()
    {
        $this->setName('winky');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $winky = new Winky($output, $this->config()->get('service.name'));

        if (!$output->isDecorated()) {
            $winky->render();
            return;
        }

        while (true) {
            $winky->render();
        }
    }
}
