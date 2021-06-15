<?php

namespace Platformsh\Cli\Command\Session;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SessionSwitchCommand extends CommandBase {
    protected $hiddenInList = true;
    protected $stability = 'beta';

    protected function configure()
    {
        $this->setName('session:switch')
            ->setDescription('Switch between sessions')
            ->addArgument('id', InputArgument::OPTIONAL, 'The new session ID');
        $this->setHelp(
            'Multiple session IDs allow you to be logged into multiple accounts at the same time.'
            . "\n\nThe default ID is \"default\"."
        );
        $this->addExample('Change to the session named "personal"', 'personal');
        $this->addExample('Change to the default session', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->config();
        $previousId = $config->getSessionId();

        $envVar = $config->get('application.env_prefix') . 'SESSION_ID';
        if (getenv($envVar) !== false) {
            $this->stdErr->writeln(sprintf('The session ID is set via the environment variable %s.', $envVar));
            $this->stdErr->writeln('It cannot be changed using this command.');
            return 1;
        }

        $newId = $input->getArgument('id');
        if ($newId === null) {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('The new session ID is required');
                return 1;
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $autocomplete = \array_merge(['default' => ''], \array_flip($this->api()->listSessionIds()));
            unset($autocomplete[$previousId]);
            $default = count($autocomplete) === 1 ? key($autocomplete) : false;
            $this->stdErr->writeln('The current session ID is: <info>' . $previousId . '</info>');
            $this->stdErr->writeln('');
            $newId = $questionHelper->askInput('Enter a new session ID', $default ?: null, \array_keys($autocomplete), function ($sessionId) {
                if (empty($sessionId)) {
                    throw new \RuntimeException('The session ID cannot be empty');
                }
                try {
                    $this->config()->validateSessionId($sessionId);
                } catch (\InvalidArgumentException $e) {
                    throw new \RuntimeException($e->getMessage());
                }

                return $sessionId;
            });
            $this->stdErr->writeln('');
        }

        if ($newId === $previousId) {
            $this->stdErr->writeln(sprintf('The session ID is already set as <info>%s</info>', $newId));
            $this->showAccountInfo();
            return 0;
        }

        $config->setSessionId($newId, true);

        // Reset the API service.
        $this->api()->getClient(false, true);

        // Set up SSH config.
        if ($this->api()->isLoggedIn()) {
            /** @var \Platformsh\Cli\Service\SshConfig $sshConfig */
            $sshConfig = $this->getService('ssh_config');
            $sshConfig->configureSessionSsh();
        }

        $this->stdErr->writeln(sprintf('Session ID changed from <info>%s</info> to <info>%s</info>', $previousId, $config->getSessionId()));

        $this->showAccountInfo();

        return 0;
    }

    private function showAccountInfo()
    {
        if ($this->api()->isLoggedIn()) {
            if ($this->api()->authApiEnabled()) {
                $user = $this->api()->getUser();
                $this->stdErr->writeln(sprintf(
                    "\nUsername: <info>%s</info>\nEmail address: <info>%s</info>",
                    $user->username,
                    $user->email
                ));
            } else {
                $info = $this->api()->getMyAccount();
                $this->stdErr->writeln(sprintf(
                    "\nUsername: <info>%s</info>\nEmail address: <info>%s</info>",
                    $info['username'],
                    $info['mail']
                ));
            }
            return;
        }
        $this->stdErr->writeln(sprintf("\nTo log in, run: <info>%s login</info>", $this->config()->get('application.executable')));
    }
}
