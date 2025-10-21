<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\SshDiagnostics;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:ssh', description: 'SSH to the current environment', aliases: ['ssh'])]
class EnvironmentSshCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Selector $selector, private readonly Shell $shell, private readonly Ssh $ssh, private readonly SshDiagnostics $sshDiagnostics)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('cmd', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'A command to run on the environment.')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the SSH URL only.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Output all SSH URLs (for every app).')
            ->addOption('option', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Pass an extra option to SSH');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addRemoteContainerOptions($this->getDefinition());
        $this->addCompleter($this->selector);
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Open a shell over SSH');
        $this->addExample('Pass an extra option to SSH', "-o 'RequestTTY force'");
        $this->addExample('List files', 'ls');
        $this->addExample("Monitor the app log (use '--' before flags)", 'tail /var/log/app.log -- -n50 -f');
        $envPrefix = $this->config->getStr('service.env_prefix');
        $this->addExample('Display relationships (use quotes for complex syntax)', "'echo \${$envPrefix}RELATIONSHIPS | base64 --decode'");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));
            $environment = $selection->getEnvironment();

            if ($input->getOption('all')) {
                $output->writeln(array_values($environment->getSshUrls()));

                return 0;
            }

            $container = $selection->getRemoteContainer();

            $sshUrl = $container->getSshUrl($input->getOption('instance'));
        } catch (EnvironmentStateException $e) {
            $environment = $e->getEnvironment();
            switch ($environment->status) {
                case 'inactive':
                    $this->stdErr->writeln(sprintf('The environment %s is inactive, so an SSH connection is not possible.', $this->api->getEnvironmentLabel($e->getEnvironment(), 'error')));
                    if (!$e->getEnvironment()->has_code) {
                        $this->stdErr->writeln('');
                        $this->stdErr->writeln('Push code to the environment to activate it.');
                    }
                    return 1;

                case 'paused':
                    $this->stdErr->writeln(sprintf('The environment %s is paused, so an SSH connection is not possible.', $this->api->getEnvironmentLabel($e->getEnvironment(), 'error')));
                    if ($this->config->isCommandEnabled('environment:resume')) {
                        $this->stdErr->writeln('');
                        $this->stdErr->writeln(sprintf('Resume the environment by running: <info>%s env:resume -e %s</info>', $this->config->getStr('application.executable'), OsUtil::escapeShellArg($environment->id)));
                    }
                    return 1;
            }
            throw $e;
        } catch (\InvalidArgumentException $e) {
            // Use Symfony's exception to print usage information.
            throw $e instanceof InvalidArgumentException ? $e : new InvalidArgumentException($e->getMessage());
        }

        if ($input->getOption('pipe')) {
            $output->write($sshUrl);
            return 0;
        }

        $remoteCommand = $input->getArgument('cmd');
        if (empty($remoteCommand) && $this->runningViaMulti) {
            throw new InvalidArgumentException('The cmd argument is required when running via "multi"');
        }
        $command = $this->ssh->getSshCommand($sshUrl, $input->getOption('option'), $remoteCommand);

        $start = \time();

        $exitCode = $this->shell->executeSimple($command, null, $this->ssh->getEnv());
        if ($exitCode !== 0) {
            if ($selection->getProject()->isSuspended()) {
                $this->stdErr->writeln('');
                $this->api->warnIfSuspended($selection->getProject());
                return $exitCode;
            }

            $diagnostics = $this->sshDiagnostics;
            $diagnostics->diagnoseFailureWithTest($sshUrl, $start, $exitCode);
        }

        return $exitCode;
    }
}
