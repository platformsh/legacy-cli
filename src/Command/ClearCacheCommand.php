<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Doctrine\Common\Cache\CacheProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'clear-cache', description: 'Clear the CLI cache', aliases: ['cc'])]
class ClearCacheCommand extends CommandBase
{
    public function __construct(private readonly CacheProvider $cacheProvider)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->setHiddenAliases(['clearcache']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cache = $this->cacheProvider;
        $cache->flushAll();
        $this->stdErr->writeln("All caches have been cleared");
        return 0;
    }
}
