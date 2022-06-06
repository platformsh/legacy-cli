<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\SshDiagnostics;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSshCommand extends CommandBase
{
    protected static $defaultName = 'environment:ssh|ssh';

    private $config;
    private $diagnostics;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        Config $config,
        Selector $selector,
        Shell $shell,
        Ssh $ssh,
        SshDiagnostics $sshDiagnostics
    ) {
        $this->config = $config;
        $this->diagnostics = $sshDiagnostics;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('cmd', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'A command to run on the environment.')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the SSH URL only.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Output all SSH URLs (for every app).')
            ->setDescription('SSH to the current environment');

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition, true);
        $this->ssh->configureInput($definition);

        $this->addExample('Open a shell over SSH');
        $this->addExample('List files', 'ls');
        $this->addExample("Monitor the app log (use '--' before options)", 'tail /var/log/app.log -- -n50 -f');
        $envPrefix = $this->config->get('service.env_prefix');
        $this->addExample('Display relationships (use quotes for complex syntax)', "'echo \${$envPrefix}RELATIONSHIPS | base64 --decode'");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $environment = $selection->getEnvironment();

        if ($input->getOption('all')) {
            $output->writeln(array_values($environment->getSshUrls()));

            return 0;
        }

        $container = $this->selector->getSelection($input)->getRemoteContainer();
        $sshUrl = $container->getSshUrl();

        if ($input->getOption('pipe')) {
            $output->write($sshUrl);

            return 0;
        }

        $remoteCommand = $input->getArgument('cmd');
        if (is_array($remoteCommand)) {
            $remoteCommand = implode(' ', $remoteCommand);
        }
        if (!$remoteCommand && $this->runningViaMulti) {
            throw new InvalidArgumentException('The cmd argument is required when running via "multi"');
        }

        $sshOptions = [];
        if ($this->isTerminal(STDIN)) {
            $sshOptions['RequestTTY'] = 'force';
        }
        $command = $this->ssh->getSshCommand($sshOptions, $sshUrl, $remoteCommand);

        $start = \time();

        $exitCode = $this->shell->executeSimple($command);
        if ($exitCode !== 0) {
            $this->diagnostics->diagnoseFailureWithTest($sshUrl, $start, $exitCode);
        }

        return $exitCode;
    }
}
