<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Client\Model\ProjectAccess;
use Platformsh\Client\Model\UserAccess\ProjectUserAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:get', description: "View a user's role(s)")]
class UserGetCommand extends UserCommandBase
{
    public function __construct(private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, "The user's email address")
            ->addOption('level', 'l', InputOption::VALUE_REQUIRED, "The role level ('project' or 'environment')")
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the role to stdout (after making any changes)');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->activityMonitor->addWaitOptions($this->getDefinition());

        // Backwards compatibility.
        $this->setHiddenAliases(['user:role']);
        $this->addOption('role', 'r', InputOption::VALUE_REQUIRED, "[Deprecated: use user:update to change a user's role(s)]");

        $this->addExample("View Alice's role on the project", 'alice@example.com');
        $this->addExample("View Alice's role on the current environment", 'alice@example.com --level environment --pipe');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
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

        $selection = $this->selector->getSelection($input, new \Platformsh\Cli\Selector\SelectorConfig(envRequired: !($level !== 'environment')));
        $project = $selection->getProject();

        $this->io->warnAboutDeprecatedOptions(['role']);

        $questionHelper = $this->questionHelper;

        // Load the user.
        $email = $input->getArgument('email');
        if ($email === null && $input->isInteractive()) {
            $email = $questionHelper->choose($this->listUsers($project), 'Enter a number to choose a user:');
        }

        $selection = $this->loadProjectUser($project, $email);
        if (!$selection) {
            $this->stdErr->writeln("User not found: <error>$email</error>");

            return 1;
        }

        if ($input->getOption('pipe')) {
            $this->displayRole($selection, $level, $output);

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
     * @param ProjectAccess|ProjectUserAccess $user
     * @param string $level
     * @param OutputInterface $output
     */
    private function displayRole($user, $level, OutputInterface $output): void
    {
        if ($level === 'environment') {
            if ($user instanceof ProjectAccess) {
                $access = $selection->getEnvironment()->getUser($user->id);
                $currentRole = $access ? $access->role : 'none';
            } else {
                $typeRoles = $user->getEnvironmentTypeRoles();
                $envType = $selection->getEnvironment()->type;
                $currentRole = isset($typeRoles[$envType]) ? $typeRoles[$envType] : 'none';
            }
        } else {
            $currentRole = $user instanceof ProjectAccess ? $user->role : $user->getProjectRole();
        }
        $output->writeln($currentRole);
    }
}
