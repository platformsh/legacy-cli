<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Command\Self\SelfInstallCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SelfInstallChecker
{
    private static bool $checkedSelfInstall = false;
    private readonly OutputInterface $stdErr;

    public function __construct(
        private readonly Config           $config,
        private readonly InputInterface   $input,
        private readonly InstallationInfo $installationInfo,
        private readonly QuestionHelper   $questionHelper,
        private readonly SubCommandRunner $subCommandRunner,
        private readonly State            $state,
        OutputInterface                   $output,
    ) {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    public function checkSelfInstall(): void
    {
        // Avoid checking more than once in this process.
        if (self::$checkedSelfInstall) {
            return;
        }
        self::$checkedSelfInstall = true;

        // Avoid if disabled, or if in non-interactive mode.
        if (!$this->config->getBool('application.prompt_self_install')
            || !$this->config->isCommandEnabled('self:install')
            || !$this->input->isInteractive()) {
            return;
        }

        // Avoid if already installed.
        if (file_exists($this->config->getUserConfigDir() . DIRECTORY_SEPARATOR . SelfInstallCommand::INSTALLED_FILENAME)) {
            return;
        }

        // Avoid if other CLIs are installed.
        if ($this->config->isWrapped() || $this->installationInfo->otherCLIsInstalled()) {
            return;
        }

        // Stop if already prompted and declined.
        if ($this->state->get('self_install.last_prompted') !== false) {
            return;
        }

        $this->stdErr->writeln('CLI resource files can be installed automatically. They provide support for autocompletion and other features.');
        $questionText = 'Do you want to install these files?';
        if (file_exists($this->config->getUserConfigDir() . DIRECTORY_SEPARATOR . '/shell-config.rc')) {
            $questionText = 'Do you want to complete the installation?';
        }
        $answer = $this->questionHelper->confirm($questionText);
        $this->state->set('self_install.last_prompted', time());
        $this->stdErr->writeln('');

        if ($answer) {
            $this->subCommandRunner->run('self:install');
        } else {
            $this->stdErr->writeln('To install at another time, run: <info>' . $this->config->getStr('application.executable') . ' self:install</info>');
        }

        $this->stdErr->writeln('');
    }
}
