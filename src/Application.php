<?php

namespace CommerceGuys\Platform\Cli;

use CommerceGuys\Platform\Cli\Helper\DrushHelper;
use CommerceGuys\Platform\Cli\Helper\FilesystemHelper;
use CommerceGuys\Platform\Cli\Helper\GitHelper;
use CommerceGuys\Platform\Cli\Helper\PlatformQuestionHelper;
use CommerceGuys\Platform\Cli\Helper\ShellHelper;
use Symfony\Component\Console\Application as ParentApplication;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Shell;

class Application extends ParentApplication {

    protected $output;

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        parent::__construct('Platform.sh CLI', '1.5-dev');

        $this->setDefaultTimezone();

        $this->addCommands($this->getCommands());

        $this->setDefaultCommand('welcome');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultInputDefinition()
    {
        // We remove the confusing `--ansi` and `--no-ansi` options.
        return new InputDefinition(array(
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this application version'),
            new InputOption('--yes', '-y', InputOption::VALUE_NONE, 'Answer "yes" to all prompts'),
            new InputOption('--no', '-n', InputOption::VALUE_NONE, 'Answer "no" to all prompts'),
            new InputOption('--shell', '-s', InputOption::VALUE_NONE, 'Launch the shell'),
        ));
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultHelperSet()
    {
        return new HelperSet(array(
            new FormatterHelper(),
            new PlatformQuestionHelper(),
            new FilesystemHelper(),
            new ShellHelper(),
            new DrushHelper(),
            new GitHelper(),
        ));
    }

    /**
     * @inheritdoc
     */
    protected function getDefaultCommands()
    {
        // Override the default commands to add a custom ListCommand.
        return array(new HelpCommand(), new Command\ListCommand());
    }

    /**
     * @return \Symfony\Component\Console\Command\Command[]
     */
    protected function getCommands()
    {
        $commands = array();
        $commands[] = new Command\CompletionCommand();
        $commands[] = new Command\PlatformLogoutCommand();
        $commands[] = new Command\PlatformLoginCommand();
        $commands[] = new Command\DrushCommand();
        $commands[] = new Command\ProjectListCommand();
        $commands[] = new Command\DomainAddCommand();
        $commands[] = new Command\DomainDeleteCommand();
        $commands[] = new Command\DomainListCommand();
        $commands[] = new Command\EnvironmentActivateCommand();
        $commands[] = new Command\EnvironmentBackupCommand();
        $commands[] = new Command\EnvironmentBranchCommand();
        $commands[] = new Command\EnvironmentCheckoutCommand();
        $commands[] = new Command\EnvironmentDeactivateCommand();
        $commands[] = new Command\EnvironmentDeleteCommand();
        $commands[] = new Command\EnvironmentListCommand();
        $commands[] = new Command\EnvironmentMergeCommand();
        $commands[] = new Command\EnvironmentRelationshipsCommand();
        $commands[] = new Command\EnvironmentSshCommand();
        $commands[] = new Command\EnvironmentSynchronizeCommand();
        $commands[] = new Command\EnvironmentUrlCommand();
        $commands[] = new Command\EnvironmentVariableDeleteCommand();
        $commands[] = new Command\EnvironmentVariableGetCommand();
        $commands[] = new Command\EnvironmentVariableSetCommand();
        $commands[] = new Command\ProjectBuildCommand();
        $commands[] = new Command\ProjectCleanCommand();
        $commands[] = new Command\ProjectDrushAliasesCommand();
        $commands[] = new Command\ProjectGetCommand();
        $commands[] = new Command\ProjectInitCommand();
        $commands[] = new Command\SshKeyAddCommand();
        $commands[] = new Command\SshKeyDeleteCommand();
        $commands[] = new Command\SshKeyListCommand();
        $commands[] = new Command\WelcomeCommand();
        return $commands;
    }

    /**
     * @inheritdoc
     */
    public function getHelp()
    {
        $messages = array(
          $this->getLongVersion(),
          '',
          '<comment>Global options:</comment>',
        );

        foreach ($this->getDefinition()->getOptions() as $option) {
            $messages[] = sprintf('  %-29s %s %s',
              '<info>--'.$option->getName().'</info>',
              $option->getShortcut() ? '<info>-'.$option->getShortcut().'</info>' : '  ',
              $option->getDescription()
            );
        }

        return implode(PHP_EOL, $messages);
    }

    /**
     * {@inheritdoc}
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        // Set the input to non-interactive if the yes or no options are used.
        if ($input->hasParameterOption(array('--yes', '-y')) || $input->hasParameterOption(array('--no', '-n'))) {
            $input->setInteractive(false);
        }
        // Enable the shell.
        elseif ($input->hasParameterOption(array('--shell', '-s'))) {
            $shell = new Shell($this);
            $shell->run();
            return 0;
        }

        $this->output = $output;
        return parent::doRun($input, $output);
    }

    /**
     * @return OutputInterface
     */
    public function getOutput() {
        if (isset($this->output)) {
            return $this->output;
        }
        $stream = fopen('php://stdout', 'w');
        return new StreamOutput($stream);
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

}
