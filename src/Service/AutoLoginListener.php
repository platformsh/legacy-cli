<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Event\LoginRequiredEvent;
use Platformsh\Cli\Exception\LoginRequiredException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

readonly class AutoLoginListener
{
    private OutputInterface $stdErr;

    public function __construct(
        private Api              $api,
        private SubCommandRunner $commandDispatcher,
        private Config           $config,
        private InputInterface   $input,
        private QuestionHelper   $questionHelper,
        private Url              $urlService,
        OutputInterface          $output,
    ) {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Log in the user.
     *
     * @see Api::getClient()
     */
    public function onLoginRequired(LoginRequiredEvent $event): void
    {
        $success = false;
        if ($this->input->isInteractive()) {
            $sessionAdvice = [];
            if ($this->config->getSessionId() !== 'default' || count($this->api->listSessionIds()) > 1) {
                $sessionAdvice[] = sprintf('The current session ID is: <info>%s</info>', $this->config->getSessionId());
                if (!$this->config->isSessionIdFromEnv()) {
                    $sessionAdvice[] = sprintf('To switch sessions, run: <info>%s session:switch</info>', $this->config->getStr('application.executable'));
                }
            }

            if ($this->urlService->canOpenUrls()) {
                $this->stdErr->writeln($event->getMessage());
                $this->stdErr->writeln('');
                if ($sessionAdvice) {
                    $this->stdErr->writeln($sessionAdvice);
                    $this->stdErr->writeln('');
                }
                if ($this->questionHelper->confirm('Log in via a browser?')) {
                    $this->stdErr->writeln('');
                    $exitCode = $this->commandDispatcher->run('auth:browser-login', $event->getLoginOptions());
                    $this->stdErr->writeln('');
                    $success = $exitCode === 0;
                }
            }
        }
        if (!$success) {
            $e = new LoginRequiredException();
            $e->setMessageFromEvent($event);
            throw $e;
        }
    }
}
