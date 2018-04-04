<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
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
            ->setName('user:get')
            ->setDescription("View a user's role(s)")
            ->addArgument('email', InputArgument::OPTIONAL, "The user's email address")
            ->addOption('level', 'l', InputOption::VALUE_REQUIRED, "The role level ('project' or 'environment')")
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the role to stdout');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();

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

        $this->validateInput($input, $level !== 'environment');
        $project = $this->getSelectedProject();

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
            if (count($choices) > 1) {
                $this->stdErr->writeln('');
            }
        }
        $projectAccess = $this->api()->loadProjectAccessByEmail($project, $email);
        if (!$projectAccess) {
            $this->stdErr->writeln("User not found: <error>$email</error>");

            return 1;
        }

        if ($input->getOption('pipe')) {
            if ($level !== 'environment') {
                $currentRole = $projectAccess->role;
            } else {
                $currentRole = $projectAccess->role === ProjectAccess::ROLE_ADMIN ? 'admin' : false;
                $accesses = $this->getSelectedEnvironment()->getUsers();
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
                        $this->getSelectedEnvironment()->id
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

        return $this->runOtherCommand('user:add', $args, $output);
    }
}
