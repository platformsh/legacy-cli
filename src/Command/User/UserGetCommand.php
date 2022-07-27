<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\Selection;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Client\Model\ProjectAccess;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UserGetCommand extends CommandBase
{
    protected static $defaultName = 'user:get';
    protected static $defaultDescription = "View a user's role(s)";

    private $activityService;
    private $api;
    private $questionHelper;
    private $selector;
    private $subCommandRunner;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        QuestionHelper $questionHelper,
        Selector $selector,
        SubCommandRunner $subCommandRunner
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->subCommandRunner = $subCommandRunner;
        parent::__construct();

        // Backwards compatibility.
        $this->setHiddenAliases(['user:role']);
    }

    protected function configure()
    {
        $this->addArgument('email', InputArgument::OPTIONAL, "The user's email address")
            ->addOption('level', 'l', InputOption::VALUE_REQUIRED, "The role level ('project' or 'environment')")
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the role to stdout');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);

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

        $selection = $this->selector->getSelection($input, $level !== 'environment');
        $project = $selection->getProject();

        // Load the user.
        $email = $input->getArgument('email');
        if ($email === null && $input->isInteractive()) {
            $choices = [];
            foreach ($this->api->getProjectAccesses($project) as $access) {
                $account = $this->api->getAccount($access);
                $choices[$account['email']] = sprintf('%s (%s)', $account['display_name'], $account['email']);
            }
            $email = $this->questionHelper->choose($choices, 'Enter a number to choose a user:');
        }
        $projectAccess = $this->api->loadProjectAccessByEmail($project, (string) $email);
        if (!$projectAccess) {
            $this->stdErr->writeln("User not found: <error>$email</error>");

            return 1;
        }

        if ($input->getOption('pipe')) {
            $this->displayRole($projectAccess, $level, $output, $selection);

            return 0;
        }

        $args = [
            'email' => $email,
            '--project' => $project->id,
            '--yes' => true,
        ];

        return $this->subCommandRunner->run('user:add', $args, $output);
    }

    /**
     * @param \Platformsh\Client\Model\ProjectAccess $projectAccess
     * @param string $level
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param Selection $selection
     */
    private function displayRole(ProjectAccess $projectAccess, $level, OutputInterface $output, Selection $selection)
    {
        if ($level !== 'environment') {
            $currentRole = $projectAccess->role;
        } else {
            $access = $selection->getEnvironment()->getUser($projectAccess->id);
            $currentRole = $access ? $access->role : 'none';
        }
        $output->writeln($currentRole);
    }
}
