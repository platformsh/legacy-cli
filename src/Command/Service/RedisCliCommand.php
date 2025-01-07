<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Service;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'service:redis-cli', description: 'Access the Redis CLI', aliases: ['redis'])]
class RedisCliCommand extends ValkeyCliCommandBase
{
    protected string $dbName = 'redis';
    protected string $dbTitle = 'Redis';
    protected string $dbCommand = 'redis-cli';
}
