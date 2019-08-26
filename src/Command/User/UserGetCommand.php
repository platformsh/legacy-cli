<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\EnvironmentAccess;
use Platformsh\Client\Model\ProjectAccess;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

class UserGetCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('user:get')
            ->setDescription("View a user's role(s)")
            ->addArgument('email', InputArgument::OPTIONAL, "The user's email address")
            ->addOption('level', 'l', InputOption::VALUE_REQUIRED, "The role level ('project' or 'environment')")
            ->addOption('pipe', 'o', InputOption::VALUE_NONE, 'Output the role to stdout (after making any changes)');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();

        // Backwards compatibility.
        $this->setHiddenAliases(['user:role']);
        $this->addOption('role', 'r', InputOption::VALUE_REQUIRED, "[Deprecated: use user:update to change a user's role(s)]");

        $this->addExample("View Alice's role on the project", 'alice@example.com');
        $this->addExample("View Alice's role on the environment", 'alice@example.com --level environment');
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

        $this->warnAboutDeprecatedOptions(['role']);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        // Load the user.
        $email = $input->getArgument('email');
        if ($email === null && $input->isInteractive()) {
            $choices = [];
            foreach ($this->api()->getProjectAccesses($project) as $access) {
                $account = $this->api()->getAccount($access);
                $choices[$account['email']] = sprintf('%s (%s)', $account['display_name'], $account['email']);
            }
            $email = $questionHelper->choose($choices, 'Enter a number to choose a user:');
        }
        $projectAccess = $this->api()->loadProjectAccessByEmail($project, $email);
        if (!$projectAccess) {
            $this->stdErr->writeln("User not found: <error>$email</error>");

            return 1;
        }

        if ($input->getOption('pipe') && !$role) {
            $this->displayRole($projectAccess, $level, $output);

            return 0;
        }

        if ($level === null && $role && $this->hasSelectedEnvironment() && $input->isInteractive()) {
            $environment = $this->getSelectedEnvironment();
            $question = new ChoiceQuestion('What role level do you want to set to "' . $role . '"?', [
                'project' => 'The project',
                'environment' => sprintf('The environment (%s)', $environment->id),
            ]);
            $level = $questionHelper->ask($input, $output, $question);
            $this->stdErr->writeln('');
        } elseif ($level === null && $role) {
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

        $args = [
            'email' => $email,
            '--role' => [],
            '--project' => $project->id,
        ];
        if ($role) {
            if ($level === 'project') {
                $args['--role'][] = $role;
            } elseif ($level === 'environment') {
                $args['--role'][] = $this->getSelectedEnvironment()->id . ':' . $role;
            }
        } else {
            $args['--yes'] = true;
        }
        $result = $this->runOtherCommand($role ? 'user:update' : 'user:add', $args, $output);
        if ($result !== 0) {
            return $result;
        }

        if ($input->getOption('pipe')) {
            if ($level !== 'environment') {
                $projectAccess->refresh();
            }
            $this->displayRole($projectAccess, $level, $output);
        }

        return 0;
    }

    /**
     * @param \Platformsh\Client\Model\ProjectAccess            $projectAccess
     * @param string                                            $level
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    private function displayRole(ProjectAccess $projectAccess, $level, OutputInterface $output)
    {
        if ($level !== 'environment') {
            $currentRole = $projectAccess ? $projectAccess->role : 'none';
        } else {
            $access = $this->getSelectedEnvironment()->getUser($projectAccess->id);
            $currentRole = $access ? $access->role : 'none';
        }
        $output->writeln($currentRole);
    }
}
