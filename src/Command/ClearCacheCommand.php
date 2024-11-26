<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'clear-cache', description: 'Clear the CLI cache', aliases: ['cc'])]
class ClearCacheCommand extends CommandBase
{
    protected $local = true;

    protected function configure()
    {
        $this
            ->setHiddenAliases(['clearcache']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var \Doctrine\Common\Cache\CacheProvider $cache */
        $cache = $this->getService('cache');
        $cache->flushAll();
        $this->stdErr->writeln("All caches have been cleared");
        return 0;
    }
}
