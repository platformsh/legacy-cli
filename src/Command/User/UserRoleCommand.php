<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Client\Model\EnvironmentAccess;
use Platformsh\Client\Model\ProjectAccess;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UserRoleCommand extends CommandBase
{

    protected function configure()
    {
        $this
          ->setName('user:role')
          ->setDescription("View or change a user's role")
          ->addArgument('email', InputArgument::REQUIRED, "The user's email address")
          ->addOption('role', 'r', InputOption::VALUE_REQUIRED, "A new role for the user")
          ->addOption('level', 'l', InputOption::VALUE_REQUIRED, "The role level ('project' or 'environment')", 'project')
          ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the role only');
        $this->addProjectOption()
          ->addEnvironmentOption()
          ->addNoWaitOption();
        $this->addExample("View Alice's role on the project", 'alice@example.com');
        $this->addExample("View Alice's role on the environment", 'alice@example.com --level environment');
        $this->addExample("Give Alice the 'contributor' role on the environment 'test'", 'alice@example.com --level environment --environment test --role contributor');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $level = $input->getOption('level');
        $validLevels = ['project', 'environment'];
        if (!in_array($level, $validLevels)) {
            $this->stdErr->writeln("Invalid level: <error>$level</error>");
            return 1;
        }

        $this->validateInput($input, true);

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
            $this->stdErr->writeln("User not found: <error>$email</error>");
            return 1;
        }

        $currentRole = null;
        $validRoles = ProjectAccess::$roles;
        if ($level == 'project') {
            $currentRole = $selectedUser['role'];
        }
        elseif ($level == 'environment') {
            if (!$this->hasSelectedEnvironment()) {
                $this->stdErr->writeln('You must specify an environment');
                return 1;
            }
            $currentRole = $selectedUser->getEnvironmentRole($this->getSelectedEnvironment());
            $validRoles = EnvironmentAccess::$roles;
        }

        $role = $input->getOption('role');
        if ($role && $role !== 'none' && !in_array($role, $validRoles)) {
            $this->stdErr->writeln("Invalid role: $role");
            return 1;
        }

        if ($role && $project->owner === $selectedUser->id) {
            $this->stdErr->writeln("The user <error>$email</error> is the owner of the project <error>{$project->title}</error>.");
            $this->stdErr->writeln("You cannot change the role of the project's owner.");
            return 1;
        }

        if ($role === $currentRole || ($role === 'none' && $currentRole === false)) {
            $this->stdErr->writeln("There is nothing to change");
        }
        elseif ($role && $level == 'project') {
            $result = $selectedUser->update(['role' => $role]);
            $this->stdErr->writeln("User <info>$email</info> updated");
        }
        elseif ($role && $level == 'environment') {
            $environment = $this->getSelectedEnvironment();
            $access = $environment->getUser($selectedUser->id);
            if ($role == 'none') {
                if ($access) {
                    $result = $access->delete();
                }
            }
            elseif ($access) {
                $result = $access->update(['role' => $role]);
            }
            else {
                $result = $environment->addUser($selectedUser->id, $role);
            }
            $this->stdErr->writeln("User <info>$email</info> updated");
        }

        if (isset($result) && !$input->getOption('no-wait')) {
            ActivityUtil::waitMultiple($result->getActivities(), $this->stdErr);
        }

        if ($input->getOption('pipe')) {
            if ($level == 'project') {
                $output->writeln($selectedUser->role);
            } elseif ($level == 'environment') {
                $environment = $this->getSelectedEnvironment();
                $output->writeln($selectedUser->getEnvironmentRole($environment) ?: 'none');
            }

            return 0;
        }

        if ($level == 'project') {
            $output->writeln("Project role: <info>{$selectedUser->role}</info>");
        } elseif ($level == 'environment') {
            $environment = $this->getSelectedEnvironment();
            $environmentRole = $selectedUser->getEnvironmentRole($environment) ?: 'none';
            $output->writeln("Role for environment {$environment->title}: <info>$environmentRole</info>");
        }

        return 0;
    }
}
