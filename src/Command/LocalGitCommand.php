<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LocalGitCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('local:git')
          ->setAliases(array('git'))
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
            throw new \Exception('Unexpected input type');
        }

        $inputString = $input->__toString();
        if (substr_count($inputString, ' ') >= 1) {
            list(, $gitCommand) = explode(' ', $input->__toString(), 2);
        }
        else {
            $gitCommand = 'status';
        }

        chdir($projectRoot . '/' . LocalProject::REPOSITORY_DIR);
        passthru('git ' . $gitCommand, $return_var);
        return $return_var;
    }
}
