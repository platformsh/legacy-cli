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
use Symfony\Component\Console\Question\ChoiceQuestion;

class UserRoleCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('user:role')
            ->setDescription("View or change a user's role")
            ->addArgument('email', InputArgument::REQUIRED, "The user's email address")
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED, "A new role for the user")
            ->addOption('level', 'l', InputOption::VALUE_REQUIRED, "The role level ('project' or 'environment')")
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
        $role = $input->getOption('role');
        $validLevels = ['project', 'environment', null];
        if (!in_array($level, $validLevels)) {
            $this->stdErr->writeln("Invalid level: <error>$level</error>");
            return 1;
        }

        $this->validateInput($input, $level !== 'environment');
        $project = $this->getSelectedProject();

        if ($level === null && $role && $this->hasSelectedEnvironment() && $input->isInteractive()) {
            $environment = $this->getSelectedEnvironment();
            /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            $question = new ChoiceQuestion('For which access level do you want to set the role?', [
                'project' => 'The project',
                'environment' => sprintf('The environment (%s)', $environment->id),
            ]);
            $level = $questionHelper->ask($input, $output, $question);
        }
        elseif ($level === null && $role) {
            $level = 'project';
        }

        // Validate the --role option, according to the level.
        $validRoles = $level !== 'environment'
            ? ProjectAccess::$roles
            : array_merge(EnvironmentAccess::$roles, ['none']);
        if ($role && !in_array($role, $validRoles)) {
            $this->stdErr->writeln('Invalid ' . $level .' role: ' . $role);

            return 1;
        }

        // Load the user.
        $email = $input->getArgument('email');
        $selectedUser = $this->api()->loadProjectAccessByEmail($project, $email);
        if (!$selectedUser) {
            $this->stdErr->writeln("User not found: <error>$email</error>");

            return 1;
        }

        // Get the current role.
        if ($level !== 'environment') {
            $currentRole = $selectedUser->role;
            $environmentAccess = false;
        }
        else {
            $environmentAccess = $this->getSelectedEnvironment()->getUser($selectedUser->id);
            $currentRole = $environmentAccess === false ? 'none' : $environmentAccess->role;
        }

        if ($role === $currentRole) {
            $this->stdErr->writeln("There is nothing to change");
        }
        elseif ($role && $project->owner === $selectedUser->id) {
            $this->stdErr->writeln(sprintf(
                'The user <error>%s</error> is the owner of the project %s.',
                $email,
                $this->api()->getProjectLabel($project, 'error')
            ));
            $this->stdErr->writeln("You cannot change the role of the project's owner.");
            return 1;
        }
        elseif ($role && $level === 'environment' && $selectedUser->role === ProjectAccess::ROLE_ADMIN) {
            $this->stdErr->writeln(sprintf(
                'The user <error>%s</error> is an admin on the project %s.',
                $email,
                $this->api()->getProjectLabel($project, 'error')
            ));
            $this->stdErr->writeln('You cannot change the environment-level role of a project admin.');
            return 1;
        }
        elseif ($role && $level !== 'environment') {
            $result = $selectedUser->update(['role' => $role]);
            $this->stdErr->writeln("User <info>$email</info> updated");
        }
        elseif ($role && $level === 'environment') {
            $environment = $this->getSelectedEnvironment();
            if ($role === 'none') {
                if ($environmentAccess instanceof EnvironmentAccess) {
                    $result = $environmentAccess->delete();
                }
            }
            elseif ($environmentAccess instanceof EnvironmentAccess) {
                $result = $environmentAccess->update(['role' => $role]);
            }
            else {
                $result = $environment->addUser($selectedUser->id, $role);
            }
            $this->stdErr->writeln("User <info>$email</info> updated");
        }

        if (isset($result) && !$input->getOption('no-wait')) {
            ActivityUtil::waitMultiple($result->getActivities(), $this->stdErr, $project);
        }

        if ($input->getOption('pipe')) {
            if ($level !== 'environment') {
                $output->writeln($selectedUser->role);
            } else {
                $access = $this->getSelectedEnvironment()->getUser($selectedUser->id);
                $output->writeln($access ? $access->role : 'none');
            }

            return 0;
        }

        if ($level !== 'environment') {
            $output->writeln("Project role: <info>{$selectedUser->role}</info>");
        }

        $environments = [];
        if ($level === 'environment') {
            $environments = [$this->getSelectedEnvironment()];
        }
        elseif ($level === null && $selectedUser->role !== ProjectAccess::ROLE_ADMIN) {
            $environments = $this->api()->getEnvironments($project);
            $this->api()->sortResources($environments, 'id');
            if ($this->hasSelectedEnvironment()) {
                $environment = $this->getSelectedEnvironment();
                unset($environments[$environment->id]);
                array_splice($environments, 0, 0, [$environment->id => $environment]);
            }
        }

        foreach ($environments as $environment) {
            $access = $environment->getUser($selectedUser->id);
            $output->writeln(sprintf('Role for environment %s: <info>%s</info>', $environment->id, $access ? $access->role : 'none'));
        }

        return 0;
    }
}
