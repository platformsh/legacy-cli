<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Service;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'service:valkey-cli', description: 'Access the Valkey CLI', aliases: ['valkey'])]
class ValkeyCliCommand extends ValkeyCliCommandBase {}
