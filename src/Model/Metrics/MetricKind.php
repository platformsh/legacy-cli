<?php

namespace Platformsh\Cli\Model\Metrics;

class MetricKind
{
    const CPU_USED = 'cpu_used';
    const CPU_LIMIT = 'cpu_limit';
    const MEMORY_USED = 'memory_used';
    const MEMORY_LIMIT = 'memory_limit';
    const DISK_USED = 'disk_used';
    const DISK_LIMIT = 'disk_limit';
    const INODES_USED = 'inodes_used';
    const INODES_LIMIT = 'inodes_limit';
    const SWAP_USED = 'swap_used';
    const SWAP_LIMIT = 'swap_limit';

    const API_TYPE_CPU = 'cpu';
    const API_TYPE_MEMORY = 'memory';
    const API_TYPE_DISK = 'disk';
    const API_TYPE_INODES = 'inodes';
    const API_TYPE_SWAP = 'swap';

    const API_AGG_AVG = 'avg';
}
