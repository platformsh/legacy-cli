<?php

namespace CommerceGuys\Platform\Cli;

use CommerceGuys\Platform\Cli\Console\PlatformQuestionHelper;
use Symfony\Component\Console;

use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Helper\TableHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class Application extends Console\Application {

    protected $output;

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        parent::__construct('Platform CLI', '1.1.0');

        $this->setDefaultTimezone();

        $this->add(new Command\PlatformLogoutCommand);
        $this->add(new Command\PlatformLoginCommand);
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
        $this->add(new Command\EnvironmentUrlCommand);
        $this->add(new Command\ProjectBuildCommand);
        $this->add(new Command\ProjectCleanCommand);
        $this->add(new Command\ProjectDrushAliasesCommand);
        $this->add(new Command\ProjectGetCommand);
        $this->add(new Command\SshKeyAddCommand);
        $this->add(new Command\SshKeyDeleteCommand);
        $this->add(new Command\SshKeyListCommand);
        $this->add(new Command\WelcomeCommand);

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
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message.'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Do not output any message.'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this application version.'),
            new InputOption('--yes', '-y', InputOption::VALUE_NONE, 'Answer "yes" to all prompts.'),
            new InputOption('--no', '-n', InputOption::VALUE_NONE, 'Answer "no" to all prompts.'),
            new InputOption('--shell', '-s', InputOption::VALUE_NONE, 'Launch the shell.'),
        ));
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultHelperSet()
    {
        return new HelperSet(array(
          new FormatterHelper(),
          new DialogHelper(),
          new ProgressHelper(),
          new TableHelper(),
          new PlatformQuestionHelper(),
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
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
