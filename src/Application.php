<?php

namespace CommerceGuys\Platform\Cli;

use Symfony\Component\Console;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Shell;


class Application extends Console\Application {

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        parent::__construct('Platform CLI', '0.1');

        $this->setDefaultTimezone();
        $this->getDefinition()->addOption(new InputOption('--shell', '-s', InputOption::VALUE_NONE, 'Launch the shell.'));

        $this->add(new Command\PlatformLogoutCommand);
        $this->add(new Command\DrushCommand);
        $this->add(new Command\ProjectListCommand);
        $this->add(new Command\DomainAddCommand);
        $this->add(new Command\DomainDeleteCommand);
        $this->add(new Command\DomainListCommand);        
        $this->add(new Command\EnvironmentActivateCommand);
        $this->add(new Command\EnvironmentBackupCommand);
        $this->add(new Command\EnvironmentBranchCommand);
        $this->add(new Command\EnvironmentCheckoutCommand);
        $this->add(new Command\EnvironmentDeactivateCommand);
        $this->add(new Command\EnvironmentDeleteCommand);
        $this->add(new Command\EnvironmentListCommand);
        $this->add(new Command\EnvironmentMergeCommand);
        $this->add(new Command\EnvironmentRelationshipsCommand);        
        $this->add(new Command\EnvironmentSshCommand);
        $this->add(new Command\EnvironmentSynchronizeCommand);        
        $this->add(new Command\ProjectBuildCommand);
        $this->add(new Command\ProjectDeleteCommand);
        $this->add(new Command\ProjectFixAliasesCommand);        
        $this->add(new Command\ProjectGetCommand);
        $this->add(new Command\SshKeyAddCommand);
        $this->add(new Command\SshKeyDeleteCommand);
        $this->add(new Command\SshKeyListCommand);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultInputDefinition()
    {
        // We remove the confusing `--ansi` and `--no-ansi` options.
        return new InputDefinition(array(
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),

            new InputOption('--help',           '-h', InputOption::VALUE_NONE, 'Display this help message.'),
            new InputOption('--quiet',          '-q', InputOption::VALUE_NONE, 'Do not output any message.'),
            new InputOption('--verbose',        '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug'),
            new InputOption('--version',        '-V', InputOption::VALUE_NONE, 'Display this application version.'),
            new InputOption('--no-interaction', '-n', InputOption::VALUE_NONE, 'Do not ask any interactive question.'),
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        if (true === $input->hasParameterOption(array('--shell', '-s'))) {
            $shell = new Shell($this);
            $shell->run();

            return 0;
        }
        if (true === $input->hasParameterOption(array('--version', '-V'))) {
            $output->writeln($this->getLongVersion());

            return 0;
        }
        $name = $this->getCommandName($input);
        if ($name) {
            $command = $this->find($name);
        } else {
            $command = new Command\WelcomeCommand($this->find('projects'), $this->find('environments'));
            $command->setApplication($this);
            $input = new ArrayInput(array('command' => 'welcome'));
        }

        if (true === $input->hasParameterOption(array('--help', '-h'))) {
            if (!$name) {
                $command = $this->find('help');
                $input = new ArrayInput(array('command' => 'help'));
            } else {
                $this->wantHelps = true;
            }
        }

        $commandChain = array();
        // The CLI hasn't been configured, login must run first.
        if (!$this->hasConfiguration() && !$command::skipLogin()) {
            $this->add(new Command\LoginCommand);
            $commandChain[] = array(
                'command' => $this->find('login'),
                'input' => new ArrayInput(array('command' => 'login')),
            );
        }
        $commandChain[] = array(
            'command' => $command,
            'input' => $input,
        );

        foreach ($commandChain as $chainData) {
            $this->runningCommand = $chainData['command'];
            $exitCode = $this->doRunCommand($chainData['command'], $chainData['input'], $output);
            $this->runningCommand = null;
        }

        return $exitCode;
    }

    /**
     * Set the default timezone.
     *
     * PHP 5.4 has removed the autodetection of the system timezone,
     * so it needs to be done manually.
     * UTC is the fallback in case autodetection fails.
     */
    protected function setDefaultTimezone() {
        $timezone = 'UTC';
        if (is_link('/etc/localtime')) {
            // Mac OS X (and older Linuxes)
            // /etc/localtime is a symlink to the timezone in /usr/share/zoneinfo.
            $filename = readlink('/etc/localtime');
            if (strpos($filename, '/usr/share/zoneinfo/') === 0) {
                $timezone = substr($filename, 20);
            }
        } elseif (file_exists('/etc/timezone')) {
            // Ubuntu / Debian.
            $data = file_get_contents('/etc/timezone');
            if ($data) {
                $timezone = trim($data);
            }
        } elseif (file_exists('/etc/sysconfig/clock')) {
            // RHEL/CentOS
            $data = parse_ini_file('/etc/sysconfig/clock');
            if (!empty($data['ZONE'])) {
                $timezone = trim($data['ZONE']);
            }
        }

        date_default_timezone_set($timezone);
     }

    /**
     * @return string The absolute path to the user's home directory.
     */
    public function getHomeDirectory()
    {
        $home = getenv('HOME');
        if (empty($home)) {
            // Windows compatibility.
            if (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
                $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
            }
        }

        return $home;
    }

    /**
     * @return boolean Whether the user has configured the CLI.
     */
    protected function hasConfiguration()
    {
        return file_exists($this->getHomeDirectory() . '/.platform');
    }

}
