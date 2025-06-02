<?php

namespace Platformsh\Cli\Model\Metrics;

enum Aggregation: string
{
    case Avg = 'avg';
    case Max = 'max';
}
