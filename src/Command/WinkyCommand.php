<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\Winky;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'winky')]
class WinkyCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $local = true;
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $winky = new Winky($output, $this->config->get('service.name'));

        if (!$output->isDecorated()) {
            $winky->render();
            return 0;
        }

        while (true) {
            $winky->render();
        }
    }
}
