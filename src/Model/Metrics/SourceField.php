<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\Metrics;

class SourceField
{
    public function __construct(
        public readonly MetricKind $source,
        public readonly Aggregation $aggregation,
        public readonly ?string $mountpoint = null,
    ) {}
}
