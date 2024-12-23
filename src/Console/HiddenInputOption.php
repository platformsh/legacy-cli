<?php

declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Input\InputOption;

/**
 * An input option that is hidden from command help.
 *
 * Used for deprecated options that need backwards compatibility.
 */
class HiddenInputOption extends InputOption {}
