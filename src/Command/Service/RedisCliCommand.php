<?php

namespace Platformsh\Cli\Command\Service;

class RedisCliCommand extends ValkeyCliCommand
{
    protected $dbName = 'redis';
    protected $dbTitle = 'Redis';
    protected $dbCommand = 'redis-cli';
}
