<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
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

class UserRoleCommand extends CommandBase
{
    protected static $defaultName = 'user:get';

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
    }

    protected function configure()
    {
        $this->setDescription("View a user's role(s)")
            ->addArgument('email', InputArgument::OPTIONAL, "The user's email address")
            ->addOption('level', 'l', InputOption::VALUE_REQUIRED, "The role level ('project' or 'environment')")
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the role to stdout');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);

        // Backwards compatibility.
        $this->setHiddenAliases(['user:role']);

        $this->addExample("View Alice's role on the project", 'alice@example.com');
        $this->addExample("View Alice's role on the environment", 'alice@example.com --level environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
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
            if (count($choices) > 1) {
                $this->stdErr->writeln('');
            }
        }
        $projectAccess = $this->api->loadProjectAccessByEmail($project, $email);
        if (!$projectAccess) {
            $this->stdErr->writeln("User not found: <error>$email</error>");

            return 1;
        }

        if ($input->getOption('pipe')) {
            if ($level !== 'environment') {
                $currentRole = $projectAccess->role;
            } else {
                $currentRole = $projectAccess->role === ProjectAccess::ROLE_ADMIN ? 'admin' : false;
                $accesses = $selection->getEnvironment()->getUsers();
                foreach ($accesses as $access) {
                    if ($access->user === $projectAccess->id) {
                        $currentRole = $access->role;
                        break;
                    }
                }
                if (!$currentRole) {
                    $this->stdErr->writeln(sprintf(
                        'The user <error>%s</error> could not be found on the environment <error>%s</error>.',
                        $email,
                        $selection->getEnvironment()->id
                    ));

                    return 1;
                }
            }
            $output->writeln($currentRole);

            return 0;
        }

        $args = [
            'email' => $email,
            '--project' => $project->id,
            '--yes' => true,
        ];

        return $this->subCommandRunner->run('user:add', $args);
    }
}
