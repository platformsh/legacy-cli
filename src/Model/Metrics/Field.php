<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\Metrics;

class Field
{
    public function __construct(
        public readonly Format $format,
        public readonly SourceField|SourceFieldPercentage $value,
        public readonly bool $warn = true,
    ) {}
}
