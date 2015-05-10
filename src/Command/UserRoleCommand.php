<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UserRoleCommand extends UserCommand
{

    protected function configure()
    {
        $this
          ->setName('user:role')
          ->setDescription("View or change a user's role")
          ->addArgument('email', InputArgument::REQUIRED, "The user's email address")
          ->addOption('role', 'r', InputOption::VALUE_OPTIONAL, "A new role for the user")
          ->addOption('level', 'l', InputOption::VALUE_OPTIONAL, "The role level ('project' or 'environment')", 'project')
          ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the role only');
        $this->addProjectOption()
          ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $level = $input->getOption('level');
        $validLevels = array('project', 'environment');
        if (!in_array($level, $validLevels)) {
            $output->writeln("Invalid level: <error>$level</error>");
            return 1;
        }

        if (!$this->validateInput($input, $output, true)) {
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

        $currentRole = null;
        $validRoles = array('admin', 'viewer');
        if ($level == 'project') {
            $currentRole = $selectedUser['role'];
        }
        elseif ($level == 'environment') {
            if (!$this->hasSelectedEnvironment()) {
                $output->writeln('You must specify an environment');
                return 1;
            }
            $currentRole = $selectedUser->getEnvironmentRole($this->getSelectedEnvironment());
            $validRoles = array('admin', 'viewer', 'contributor');
        }

        $role = $input->getOption('role');
        if ($role && !in_array($role, $validRoles)) {
            $output->writeln("Invalid role: $role");
            return 1;
        }

        if ($role === $currentRole) {
            $output->writeln("There is nothing to change");
        }
        elseif ($role && $level == 'project') {
            $selectedUser->update(array('role' => $role));
            $output->writeln("User <info>$email</info> updated");
        }
        elseif ($role && $level == 'environment') {
            $selectedUser->changeEnvironmentRole($this->getSelectedEnvironment(), $role);
            $output->writeln("User <info>$email</info> updated");
        }

        if ($input->getOption('pipe') || !$this->isTerminal($output)) {
            if ($level == 'project') {
                $output->writeln($selectedUser->role);
            } elseif ($level == 'environment') {
                $environment = $this->getSelectedEnvironment();
                $output->writeln($selectedUser->getEnvironmentRole($environment));
            }

            return 0;
        }

        if ($level == 'project') {
            $output->writeln("Project role: <info>{$selectedUser->role}</info>");
        } elseif ($level == 'environment') {
            $environment = $this->getSelectedEnvironment();
            $environmentRole = $selectedUser->getEnvironmentRole($environment);
            $output->writeln("Role for environment {$environment->title}: <info>$environmentRole</info>");
        }

        return 0;
    }
}
