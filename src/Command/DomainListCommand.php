<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainListCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('domain')
            ->setDescription('Get a list of all existing domains for a project.');
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // @todo.
    }
}
