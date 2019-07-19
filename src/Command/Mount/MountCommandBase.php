<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;

abstract class MountCommandBase extends CommandBase
{
    /**
     * Format the mounts as an array of options for a ChoiceQuestion.
     *
     * @param array $mounts
     *
     * @return array
     */
    protected function getMountsAsOptions(array $mounts)
    {
        $options = [];
        foreach ($mounts as $path => $definition) {
            if ($definition['source'] === 'local' && isset($definition['source_path'])) {
                $options[$path] = sprintf('<question>%s</question> (shared:files/%s)', $path, $definition['source_path']);
            } else {
                $options[$path] = sprintf('<question>%s</question>: %s', $path, $definition['source']);
            }
        }

        return $options;
    }
}
