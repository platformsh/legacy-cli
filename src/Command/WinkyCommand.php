<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\Winky;
use Platformsh\Cli\Service\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WinkyCommand extends CommandBase
{
    protected static $defaultName = 'winky';
    protected $hiddenInList = true;
    protected $local = true;

    private $config;

    public function __construct(Config $config) { $this->config = $config; parent::__construct(); }

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
