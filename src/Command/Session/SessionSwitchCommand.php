<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Session;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\SshConfig;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'session:switch', description: 'Switch between sessions')]
class SessionSwitchCommand extends CommandBase
{
    protected bool $hiddenInList = true;
    protected string $stability = self::STABILITY_BETA;
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly QuestionHelper $questionHelper, private readonly SshConfig $sshConfig)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'The new session ID');
        $this->setHelp(
            'Multiple session IDs allow you to be logged into multiple accounts at the same time.'
            . "\n\nThe default ID is \"default\".",
        );
        $this->addExample('Change to the session named "personal"', 'personal');
        $this->addExample('Change to the default session', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $previousId = $this->config->getSessionId();

        $envVar = $this->config->getStr('application.env_prefix') . 'SESSION_ID';
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
            $autocomplete = \array_merge(['default' => ''], \array_flip($this->api->listSessionIds()));
            unset($autocomplete[$previousId]);
            $default = count($autocomplete) === 1 ? key($autocomplete) : false;
            $this->stdErr->writeln('The current session ID is: <info>' . $previousId . '</info>');
            $this->stdErr->writeln('');
            $newId = $this->questionHelper->askInput('Enter a new session ID', $default ?: null, \array_keys($autocomplete), function ($sessionId) {
                if (empty($sessionId)) {
                    throw new \RuntimeException('The session ID cannot be empty');
                }
                try {
                    $this->config->validateSessionId($sessionId);
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

        $this->config->setSessionId($newId, true);

        // Reset the API service.
        $this->api->getClient(false, true);

        // Set up SSH config.
        if ($this->api->isLoggedIn()) {
            $sshConfig = $this->sshConfig;
            $sshConfig->configureSessionSsh();
        }

        $this->stdErr->writeln(sprintf('Session ID changed from <info>%s</info> to <info>%s</info>', $previousId, $this->config->getSessionId()));

        $this->showAccountInfo();

        return 0;
    }

    private function showAccountInfo(): void
    {
        if ($this->api->isLoggedIn()) {
            $account = $this->api->getMyAccount();
            $this->stdErr->writeln(sprintf(
                "\nUsername: <info>%s</info>\nEmail address: <info>%s</info>",
                $account['username'],
                $account['email'],
            ));
            return;
        }
        $this->stdErr->writeln(sprintf("\nTo log in, run: <info>%s login</info>", $this->config->getStr('application.executable')));
    }
}
