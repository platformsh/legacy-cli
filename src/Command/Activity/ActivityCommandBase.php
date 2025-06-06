<?php

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityLoader;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;

class ActivityCommandBase extends CommandBase implements CompletionAwareInterface
{
    const DEFAULT_LIST_LIMIT = 10; // Display a digestible number of activities by default.
    const DEFAULT_FIND_LIMIT = 25; // This is the current limit per page of results.

    public function completeOptionValues($optionName, CompletionContext $context)
    {
        switch ($optionName) {
            case 'type':
            case 'exclude-type':
                return ActivityLoader::getAvailableTypes();
            case 'state':
                return ['in_progress', 'pending', 'complete', 'cancelled', 'staged'];
            case 'result':
                return ['success', 'failure'];
        }
        return [];
    }

    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        return [];
    }
}
