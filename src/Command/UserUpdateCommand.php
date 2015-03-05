<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class UserUpdateCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('user:update')
          ->setDescription('Update a user')
          ->addArgument('email', InputArgument::REQUIRED, "The user's email address")
          ->addOption('role', null, InputOption::VALUE_OPTIONAL, "The user's role: 'admin' or 'viewer'");
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $project = $this->getSelectedProject();

        $email = $input->getArgument('email');
        foreach ($project->getUsers() as $user) {
            $account = $user->getAccount();
            if ($account['email'] === $email) {
                $selectedUser = $user;
                break;
            }
        }
        if (empty($selectedUser)) {
            $output->writeln("User not found: <error>$email</error>");
            return 1;
        }

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $validateRole = function ($value) use ($output) {
            if (!in_array($value, array('admin', 'viewer'))) {
                $output->writeln("Valid project-level roles are 'admin' or 'viewer'");
                throw new \RuntimeException("Invalid role: $value");
            }
            return $value;
        };

        $role = $input->getOption('role');
        if ($role && !$validateRole($role)) {
            return 1;
        }
        elseif (!$role) {
            $default = $selectedUser['role'];
            $question = new Question("Role [$default]: ", $default);
            $question->setValidator($validateRole);
            $question->setMaxAttempts(5);
            $role = $questionHelper->ask($input, $output, $question);
        }

        if ($role === $selectedUser['role']) {
            $output->writeln("There is nothing to change");
            return 0;
        }

        $selectedUser->update(array('role' => $role));

        $output->writeln("The user has been updated");
        return 0;
    }

}
