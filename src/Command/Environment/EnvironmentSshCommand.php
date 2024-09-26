<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSshCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:ssh')
            ->setAliases(['ssh'])
            ->addArgument('cmd', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'A command to run on the environment.')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the SSH URL only.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Output all SSH URLs (for every app).')
            ->addOption('option', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Pass an extra option to SSH')
            ->setDescription('SSH to the current environment');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addRemoteContainerOptions();
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Open a shell over SSH');
        $this->addExample('Pass an extra option to SSH', "-o 'RequestTTY force'");
        $this->addExample('List files', 'ls');
        $this->addExample("Monitor the app log (use '--' before flags)", 'tail /var/log/app.log -- -n50 -f');
        $envPrefix = $this->config()->get('service.env_prefix');
        $this->addExample('Display relationships (use quotes for complex syntax)', "'echo \${$envPrefix}RELATIONSHIPS | base64 --decode'");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->chooseEnvFilter = $this->filterEnvsMaybeActive();
        $this->validateInput($input);
        $environment = $this->getSelectedEnvironment();

        if ($input->getOption('all')) {
            $output->writeln(array_values($environment->getSshUrls()));

            return 0;
        }

        try {
            $container = $this->selectRemoteContainer($input);
        } catch (EnvironmentStateException $e) {
            if ($e->getEnvironment()->id === $environment->id && $e->getEnvironment()->status === 'inactive') {
                $this->stdErr->writeln(sprintf('The environment %s is inactive, so an SSH connection is not possible.', $this->api()->getEnvironmentLabel($e->getEnvironment(), 'error')));
                if (!$e->getEnvironment()->has_code) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln('Push code to the environment to activate it.');
                }
                return 1;
            }
            throw $e;
        }

        try {
            $sshUrl = $container->getSshUrl($input->getOption('instance'));
        } catch (\InvalidArgumentException $e) {
            // Use Symfony's exception to print usage information.
            throw new InvalidArgumentException($e->getMessage());
        }

        if ($input->getOption('pipe')) {
            $output->write($sshUrl);
            return 0;
        }

        $remoteCommand = $input->getArgument('cmd');
        if (empty($remoteCommand) && $this->runningViaMulti) {
            throw new InvalidArgumentException('The cmd argument is required when running via "multi"');
        }

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $command = $ssh->getSshCommand($sshUrl, $input->getOption('option'), $remoteCommand);

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $start = \time();

        $exitCode = $shell->executeSimple($command, null, $ssh->getEnv());
        if ($exitCode !== 0) {
            if ($this->getSelectedProject()->isSuspended()) {
                $this->stdErr->writeln('');
                $this->warnIfSuspended($this->getSelectedProject());
                return $exitCode;
            }

            /** @var \Platformsh\Cli\Service\SshDiagnostics $diagnostics */
            $diagnostics = $this->getService('ssh_diagnostics');
            $diagnostics->diagnoseFailureWithTest($sshUrl, $start, $exitCode);
        }

        return $exitCode;
    }
}
