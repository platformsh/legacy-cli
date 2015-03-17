<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GitCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('git')
          ->setDescription('Run a Git command in the local repository');
        $this->ignoreValidationErrors();
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new \Exception('This can only be run from inside a project directory');
        }

        if (!$input instanceof ArgvInput) {
            return 1;
        }

        list(, $gitCommand) = explode(' ', $input->__toString(), 2);
        chdir($projectRoot . '/' . LocalProject::REPOSITORY_DIR);
        passthru('git ' . $gitCommand, $return_var);
        return $return_var;
    }
}
