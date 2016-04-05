<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\Bot;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BotCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $local = true;

    protected function configure()
    {
        $this->setName('bot')->setDescription('The ' . self::$config->get('application.name') . ' Bot');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $bot = new Bot($output, self::$config->get('service.name'));

        if (!$output->isDecorated()) {
            $bot->render();
            return;
        }

        // Stay positive: return code 0 when the user quits.
        if (function_exists('pcntl_signal')) {
            declare(ticks = 1);
            pcntl_signal(SIGINT, function () {
                echo "\n";
                exit;
            });
        }

        while (true) {
            $bot->render();
        }
    }
}
