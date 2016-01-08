<?php
namespace Platformsh\Cli;

use Platformsh\Cli\Console\EventSubscriber;
use Platformsh\Cli\Helper\DrushHelper;
use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\PlatformQuestionHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Symfony\Component\Console\Application as ParentApplication;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Shell;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Application extends ParentApplication
{

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        parent::__construct('Platform.sh CLI', '2.11.1');

        $this->setDefaultTimezone();

        $this->addCommands($this->getCommands());

        $this->setDefaultCommand('welcome');

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new EventSubscriber());
        $this->setDispatcher($dispatcher);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultInputDefinition()
    {
        // We remove the confusing `--ansi` and `--no-ansi` options.
        return new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this application version'),
            new InputOption('--yes', '-y', InputOption::VALUE_NONE, 'Answer "yes" to all prompts'),
            new InputOption('--no', '-n', InputOption::VALUE_NONE, 'Answer "no" to all prompts'),
            new InputOption('--shell', '-s', InputOption::VALUE_NONE, 'Launch the shell'),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultHelperSet()
    {
        return new HelperSet([
            new FormatterHelper(),
            new PlatformQuestionHelper(),
            new FilesystemHelper(),
            new ShellHelper(),
            new DrushHelper(),
            new GitHelper(),
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function getDefaultCommands()
    {
        // Override the default commands to add a custom HelpCommand and
        // ListCommand.
        return [new Command\HelpCommand(), new Command\ListCommand()];
    }

    /**
     * @return \Symfony\Component\Console\Command\Command[]
     */
    protected function getCommands()
    {
        static $commands = [];
        if (count($commands)) {
            return $commands;
        }

        $commands[] = new Command\BotCommand();
        $commands[] = new Command\ClearCacheCommand();
        $commands[] = new Command\CompletionCommand();
        $commands[] = new Command\DocsCommand();
        $commands[] = new Command\Activity\ActivityListCommand();
        $commands[] = new Command\Activity\ActivityLogCommand();
        $commands[] = new Command\App\AppConfigGetCommand();
        $commands[] = new Command\App\AppListCommand();
        $commands[] = new Command\Auth\LogoutCommand();
        $commands[] = new Command\Auth\LoginCommand();
        $commands[] = new Command\Domain\DomainAddCommand();
        $commands[] = new Command\Domain\DomainDeleteCommand();
        $commands[] = new Command\Domain\DomainListCommand();
        $commands[] = new Command\Environment\EnvironmentActivateCommand();
        $commands[] = new Command\Environment\EnvironmentBranchCommand();
        $commands[] = new Command\Environment\EnvironmentCheckoutCommand();
        $commands[] = new Command\Environment\EnvironmentDeleteCommand();
        $commands[] = new Command\Environment\EnvironmentDrushCommand();
        $commands[] = new Command\Environment\EnvironmentHttpAccessCommand();
        $commands[] = new Command\Environment\EnvironmentListCommand();
        $commands[] = new Command\Environment\EnvironmentInfoCommand();
        $commands[] = new Command\Environment\EnvironmentMergeCommand();
        $commands[] = new Command\Environment\EnvironmentRelationshipsCommand();
        $commands[] = new Command\Environment\EnvironmentRoutesCommand();
        $commands[] = new Command\Environment\EnvironmentSshCommand();
        $commands[] = new Command\Environment\EnvironmentSqlCommand();
        $commands[] = new Command\Environment\EnvironmentSqlDumpCommand();
        $commands[] = new Command\Environment\EnvironmentSynchronizeCommand();
        $commands[] = new Command\Environment\EnvironmentUrlCommand();
        $commands[] = new Command\Environment\EnvironmentSetRemoteCommand();
        $commands[] = new Command\Integration\IntegrationAddCommand();
        $commands[] = new Command\Integration\IntegrationDeleteCommand();
        $commands[] = new Command\Integration\IntegrationGetCommand();
        $commands[] = new Command\Integration\IntegrationUpdateCommand();
        $commands[] = new Command\Local\LocalBuildCommand();
        $commands[] = new Command\Local\LocalCleanCommand();
        $commands[] = new Command\Local\LocalDrushAliasesCommand();
        $commands[] = new Command\Local\LocalDirCommand();
        $commands[] = new Command\Local\LocalInitCommand();
        $commands[] = new Command\Project\ProjectDeleteCommand();
        $commands[] = new Command\Project\ProjectGetCommand();
        $commands[] = new Command\Project\ProjectListCommand();
        $commands[] = new Command\Project\ProjectInfoCommand();
        $commands[] = new Command\Self\SelfBuildCommand();
        $commands[] = new Command\Self\SelfInstallCommand();
        $commands[] = new Command\Self\SelfUpdateCommand();
        $commands[] = new Command\Snapshot\SnapshotCreateCommand();
        $commands[] = new Command\Snapshot\SnapshotListCommand();
        $commands[] = new Command\Snapshot\SnapshotRestoreCommand();
        $commands[] = new Command\SshKey\SshKeyAddCommand();
        $commands[] = new Command\SshKey\SshKeyDeleteCommand();
        $commands[] = new Command\SshKey\SshKeyListCommand();
        $commands[] = new Command\SubscriptionInfoCommand();
        $commands[] = new Command\Tunnel\TunnelCloseCommand();
        $commands[] = new Command\Tunnel\TunnelInfoCommand();
        $commands[] = new Command\Tunnel\TunnelListCommand();
        $commands[] = new Command\Tunnel\TunnelOpenCommand();
        $commands[] = new Command\User\UserAddCommand();
        $commands[] = new Command\User\UserDeleteCommand();
        $commands[] = new Command\User\UserListCommand();
        $commands[] = new Command\User\UserRoleCommand();
        $commands[] = new Command\Variable\VariableDeleteCommand();
        $commands[] = new Command\Variable\VariableGetCommand();
        $commands[] = new Command\Variable\VariableSetCommand();
        $commands[] = new Command\WelcomeCommand();
        $commands[] = new Command\WebCommand();

        return $commands;
    }

    /**
     * @inheritdoc
     */
    public function getHelp()
    {
        $messages = [
            $this->getLongVersion(),
            '',
            '<comment>Global options:</comment>',
        ];

        foreach ($this->getDefinition()
                      ->getOptions() as $option) {
            $messages[] = sprintf(
                '  %-29s %s %s',
                '<info>--' . $option->getName() . '</info>',
                $option->getShortcut() ? '<info>-' . $option->getShortcut() . '</info>' : '  ',
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
        if ($input->hasParameterOption(['--yes', '-y']) || $input->hasParameterOption(['--no', '-n'])) {
            $input->setInteractive(false);
        } // Enable the shell.
        elseif ($input->hasParameterOption(['--shell', '-s'])) {
            $shell = new Shell($this);
            $shell->run();

            return 0;
        }

        return parent::doRun($input, $output);
    }

    /**
     * Set the default timezone.
     *
     * PHP 5.4 has removed the autodetection of the system timezone,
     * so it needs to be done manually.
     * UTC is the fallback in case autodetection fails.
     */
    protected function setDefaultTimezone()
    {
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
