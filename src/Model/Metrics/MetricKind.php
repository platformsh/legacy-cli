<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\Metrics;

enum MetricKind: string
{
    case CpuUsed = 'cpu_used';
    case CpuLimit = 'cpu_limit';
    case MemoryUsed = 'memory_used';
    case MemoryLimit = 'memory_limit';
    case DiskUsed = 'disk_used';
    case DiskLimit = 'disk_limit';
    case InodesUsed = 'inodes_used';
    case InodesLimit = 'inodes_limit';
    case SwapUsed = 'swap_used';
    case SwapLimit = 'swap_limit';

    public const API_TYPE_CPU = 'cpu';
    public const API_TYPE_MEMORY = 'memory';
    public const API_TYPE_DISK = 'disk';
    public const API_TYPE_INODES = 'inodes';
    public const API_TYPE_SWAP = 'swap';

    public const API_AGG_AVG = 'avg';
}
