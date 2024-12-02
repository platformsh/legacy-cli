<?php

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityLoader;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;

class ActivityCommandBase extends CommandBase implements CompletionAwareInterface
{
    public function completeOptionValues($optionName, CompletionContext $context)
    {
        return match ($optionName) {
            'type', 'exclude-type' => ActivityLoader::getAvailableTypes(),
            'state' => ['in_progress', 'pending', 'complete', 'cancelled'],
            'result' => ['success', 'failure'],
            default => [],
        };
    }

    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        return [];
    }
}
