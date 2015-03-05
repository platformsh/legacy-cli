<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class UserAddCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('user:add')
          ->setDescription('Add a user to the project')
          ->addOption('email', null, InputOption::VALUE_OPTIONAL, "The new user's email address")
          ->addOption('role', null, InputOption::VALUE_OPTIONAL, "The new user's role: 'admin' or 'viewer'");
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $validateEmail = function ($value) use ($output) {
            if (empty($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException("Invalid email address");
            }
            return $value;
        };
        $validateRole = function ($value) use ($output) {
            if (!in_array($value, array('admin', 'viewer'))) {
                $output->writeln("Valid project-level roles are 'admin' or 'viewer'");
                throw new \RuntimeException("Invalid role: $value");
            }
            return $value;
        };

        $email = $input->getOption('email');
        if ($email && !$validateEmail($email)) {
            return 1;
        }
        elseif (!$email) {
            $question = new Question('Email address: ');
            $question->setValidator($validateEmail);
            $question->setMaxAttempts(5);
            $email = $questionHelper->ask($input, $output, $question);
        }

        $role = $input->getOption('role');
        if ($role && !$validateRole($role)) {
            return 1;
        }
        elseif (!$role) {
            $question = new Question('Role [viewer]: ', 'viewer');
            $question->setValidator($validateRole);
            $question->setMaxAttempts(5);
            $role = $questionHelper->ask($input, $output, $question);
        }

        $project = $this->getSelectedProject();

        $users = $project->getUsers();
        foreach ($users as $user) {
            if ($user->getAccount()['email'] === $email) {
                $output->writeln("The user already exists: <comment>$email</comment>");
                return 1;
            }
        }

        $output->writeln("Adding users can result in additional charges.");
        if (!$questionHelper->confirm("Are you sure you want to add this user?", $input, $output)) {
            return 1;
        }

        $project->addUser($email, $role);

        $this->output->writeln("The user has been created");
        return 0;
    }

}
