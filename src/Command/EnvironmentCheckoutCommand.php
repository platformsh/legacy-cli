<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

class EnvironmentCheckoutCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:checkout')
            ->setAliases(array('checkout'))
            ->setDescription('Check out an environment.')
            ->addArgument(
                'branch-name',
                InputArgument::OPTIONAL,
                'The name of the branch to check out. For example: "sprint2"'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->getCurrentProject();
        if (!$project) {
            throw new \Exception('This can only be run from inside a project directory');
        }

        $branch = $input->getArgument('branch-name');
        if (empty($branch) && $input->isInteractive()) {
            $environments = $this->getEnvironments($project);
            $environmentList = array();
            foreach ($environments as $environment) {
                $environmentList[] = $environment['id'];
            }
            $chooseEnvironmentText = "Enter a number to choose which environment to check out:";
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion($chooseEnvironmentText, $environmentList);
            $question->setMaxAttempts(5);
            $machineName = $helper->ask($input, $output, $question);
        }
        elseif (empty($branch)) {
            $output->writeln("<error>No branch specified.</error>");
            return 1;
        }
        else {
            $machineName = $this->sanitizeEnvironmentId($branch);
        }

        $projectRoot = $this->getProjectRoot();

        chdir($projectRoot . '/repository');

        $existsLocal = $this->shellExec("git show-ref refs/heads/$machineName");

        // If the branch doesn't already exist locally, check whether it is a
        // Platform.sh environment.
        if (!$existsLocal) {
            if (!isset($environments)) {
                $environments = $this->getEnvironments($project);
            }
            if (!isset($environments[$machineName])) {
                $output->writeln("<error>Environment not found: $machineName</error>");
                return 1;
            }
            // Fetch from origin.
            // @todo don't assume that the Platform.sh remote is called 'origin'
            passthru('git fetch origin');
        }

        // Check out the branch.
        passthru("git checkout $machineName");
    }
}
