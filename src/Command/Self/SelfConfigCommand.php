<?php
namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SelfConfigCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $local = true;

    protected function configure()
    {
        $this
            ->setName('self:config')
            ->setDescription('Read CLI config')
            ->addArgument('value', InputArgument::OPTIONAL, 'Read a specific config value');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $formatter->displayData($output, $this->config()->getAll(), $input->getArgument('value'));
        return 0;
    }
}
