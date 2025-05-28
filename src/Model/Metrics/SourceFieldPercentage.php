<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\Metrics;

class SourceFieldPercentage
{
    public function __construct(
        public readonly SourceField $value,
        public readonly SourceField $limit,
    ) {}
}
