<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tunnel;

class Tunnel
{
    /**
     * @param array<mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly int    $localPort,
        public readonly string $remoteHost,
        public readonly int    $remotePort,
        public readonly array  $metadata,
        public ?int $pid = null,
    ) {}
}
