<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
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
            ->addArgument('cmd', InputArgument::OPTIONAL, 'A command to run on the environment.')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the SSH URL only.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Output all SSH URLs (for every app).')
            ->setDescription('SSH to the current environment');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addAppOption();
        $this->addOption('worker', null, InputOption::VALUE_REQUIRED, 'SSH to a worker');
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Read recent messages in the deploy log', "'tail /var/log/deploy.log'");
        $this->addExample('Open a shell over SSH');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $environment = $this->getSelectedEnvironment();

        if ($input->getOption('all')) {
            $output->writeln(array_values($environment->getSshUrls()));

            return 0;
        }

        $appName = $this->selectApp($input);
        $sshUrl = $environment->getSshUrl($appName);

        if ($worker = $input->getOption('worker')) {
            // Validate the worker.
            $deployment = $this->api()->getCurrentDeployment($environment);
            try {
                $deployment->getWorker($appName . '--' . $worker);
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln('Worker not found: <error>' . $worker . '</error>');

                return 1;
            }
            list($username, $rest) = explode('@', $sshUrl, 2);
            $sshUrl = $username . '--' . $worker . '@' . $rest;
        }

        $remoteCommand = $input->getArgument('cmd');
        if (!$remoteCommand && $this->runningViaMulti) {
            throw new InvalidArgumentException('The cmd argument is required when running via "multi"');
        }

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $sshOptions = [];
        $command = $ssh->getSshCommand($sshOptions);
        if ($this->isTerminal(STDIN)) {
            $command .= ' -t';
        }
        $command .= ' ' . escapeshellarg($sshUrl);
        if ($remoteCommand) {
            $command .= ' ' . escapeshellarg($remoteCommand);
        }

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        return $shell->executeSimple($command);
    }
}
