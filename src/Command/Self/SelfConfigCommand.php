<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'self:config', description: 'Read CLI config')]
class SelfConfigCommand extends CommandBase
{
    protected bool $hiddenInList = true;
    public function __construct(private readonly Config $config, private readonly PropertyFormatter $propertyFormatter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('value', InputArgument::OPTIONAL, 'Read a specific config value');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->propertyFormatter->displayData($output, $this->config->getAll(), $input->getArgument('value'));
        return 0;
    }
}
