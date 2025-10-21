<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityLoader;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;

class ActivityCommandBase extends CommandBase
{
    protected const STATE_VALUES = ['in_progress', 'pending', 'complete', 'cancelled'];
    protected const RESULT_VALUES = ['success', 'failure'];

    protected const DEFAULT_LIST_LIMIT = 10; // Display a digestible number of activities by default.
    protected const DEFAULT_FIND_LIMIT = 25; // This is the current limit per page of results.

    /**
     * Runs autocompletion for activity options.
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('type')
            || $input->mustSuggestOptionValuesFor('exclude-type')) {
            $suggestions->suggestValues(ActivityLoader::getAvailableTypes());
        } elseif ($input->mustSuggestOptionValuesFor('state')) {
            $suggestions->suggestValues(self::STATE_VALUES);
        } elseif ($input->mustSuggestOptionValuesFor('result')) {
            $suggestions->suggestValues(self::RESULT_VALUES);
        }
    }
}
