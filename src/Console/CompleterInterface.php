<?php

declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;

/**
 * An interface service classes can implement to be called from CommandBase::complete().
 */
interface CompleterInterface
{
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void;
}
