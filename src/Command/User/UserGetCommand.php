<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\ProjectAccess;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UserGetCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('user:get')
            ->setDescription("View a user's role(s)")
            ->addArgument('email', InputArgument::OPTIONAL, "The user's email address")
            ->addOption('level', 'l', InputOption::VALUE_REQUIRED, "The role level ('project' or 'environment')")
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the role to stdout (after making any changes)');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();

        // Backwards compatibility.
        $this->setHiddenAliases(['user:role']);
        $this->addOption('role', 'r', InputOption::VALUE_REQUIRED, "[Deprecated: use user:update to change a user's role(s)]");

        $this->addExample("View Alice's role on the project", 'alice@example.com');
        $this->addExample("View Alice's role on the current environment", 'alice@example.com --level environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('role')) {
            $this->stdErr->writeln('The <error>--role</error> option is no longer available for this command.');
            $this->stdErr->writeln("To change a user's roles use the <comment>user:update</comment> command.");
            return 1;
        }
        if ($input->getFirstArgument() === 'user:role') {
            $this->stdErr->writeln('The <comment>user:role</comment> command is deprecated. Use <comment>user:get</comment> or <comment>user:update</comment> instead.');
        }

        $level = $input->getOption('level');
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

        if ($input->getOption('pipe')) {
            $this->displayRole($projectAccess, $level, $output);

            return 0;
        }

        $args = [
            'email' => $email,
            '--role' => [],
            '--project' => $project->id,
            '--yes' => true,
        ];
        return $this->runOtherCommand('user:add', $args, $output);
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
