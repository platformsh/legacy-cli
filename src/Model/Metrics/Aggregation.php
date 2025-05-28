<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\Metrics;

enum Aggregation: string
{
    case Avg = 'avg';
    case Max = 'max';
}
