<?php

declare(strict_types=1);

namespace Platformsh\Cli\Exception;

use Platformsh\Cli\Service\Config;

class RootNotFoundException extends \RuntimeException
{
    public function __construct(
        $message = 'Project root not found. This can only be run from inside a project directory.',
        $code = 2,
    ) {
        // If this is a Git repository that looks like an un-configured project,
        // then suggest the "project:set-remote" command.
        if (is_dir('.git')) {
            $config = new Config();
            if (is_dir($config->getStr('service.project_config_dir')) && $config->isCommandEnabled('project:set-remote')) {
                $executable = $config->getStr('application.executable');
                $message .= "\n\nTo set the project for this Git repository, run:\n  $executable set-remote [id]";
            }
        }

        parent::__construct($message, $code);
    }
}
