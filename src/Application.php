<?php

namespace CommerceGuys\Platform\Cli;

use CommerceGuys\Platform\Cli\Command\LoginCommand;
use CommerceGuys\Platform\Cli\Command\EnvironmentBackupCommand;
use CommerceGuys\Platform\Cli\Command\EnvironmentBranchCommand;
use CommerceGuys\Platform\Cli\Command\EnvironmentCheckoutCommand;
use CommerceGuys\Platform\Cli\Command\EnvironmentDeleteCommand;
use CommerceGuys\Platform\Cli\Command\EnvironmentListCommand;
use CommerceGuys\Platform\Cli\Command\EnvironmentMergeCommand;
use CommerceGuys\Platform\Cli\Command\EnvironmentSynchronizeCommand;
use CommerceGuys\Platform\Cli\Command\ProjectDeleteCommand;
use CommerceGuys\Platform\Cli\Command\ProjectGetCommand;
use CommerceGuys\Platform\Cli\Command\ProjectListCommand;
use CommerceGuys\Platform\Cli\Command\SshKeyAddCommand;
use CommerceGuys\Platform\Cli\Command\SshKeyDeleteCommand;
use CommerceGuys\Platform\Cli\Command\SshKeyListCommand;
use CommerceGuys\Platform\Cli\Command\WelcomeCommand;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Shell;


class Application extends BaseApplication {

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        parent::__construct('Platform Cli', '0.1');

        $this->getDefinition()->addOption(new InputOption('--shell', '-s', InputOption::VALUE_NONE, 'Launch the shell.'));

        $this->add(new ProjectListCommand);

        $this->add(new EnvironmentBackupCommand);
        $this->add(new EnvironmentBranchCommand);
        $this->add(new EnvironmentCheckoutCommand);
        $this->add(new EnvironmentDeleteCommand);
        $this->add(new EnvironmentListCommand);
        $this->add(new EnvironmentMergeCommand);
        $this->add(new EnvironmentSynchronizeCommand);
        $this->add(new ProjectDeleteCommand);
        $this->add(new ProjectGetCommand);
        $this->add(new SshKeyAddCommand);
        $this->add(new SshKeyDeleteCommand);
        $this->add(new SshKeyListCommand);
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
            $command = new WelcomeCommand($this->find('projects'));
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
        if (!$this->hasConfiguration()) {
            $this->add(new LoginCommand);
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
     * @return boolean Whether the user has configured the CLI.
     */
    protected function hasConfiguration()
    {
        $homeDir = trim(shell_exec('cd ~ && pwd'));
        return file_exists($homeDir . '/.platform');
    }

}
