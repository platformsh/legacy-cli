<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Local\Toolstack\Drupal;
use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DrushCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('drush')
            ->setDescription('Run a drush command on the remote environment.')
            ->addArgument('drush', InputArgument::IS_ARRAY, 'A command and arguments to pass to Drush.', array('status'))
            ->addOption('ssh', null, InputOption::VALUE_NONE, 'Use SSH to connect directly.')
            ->addOption(
              'project',
              null,
              InputOption::VALUE_OPTIONAL,
              'The project ID'
            )
            ->addOption(
              'environment',
              null,
              InputOption::VALUE_OPTIONAL,
              'The environment ID'
            );
    }

    public function isEnabled()
    {
        $projectRoot = $this->getProjectRoot();
        if ($projectRoot) {
            return Drupal::isDrupal($projectRoot . '/repository');
        }
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $this->getHelper('drush')->ensureInstalled();

        $drushArgs = $input->getArgument('drush');

        // Pass through options that the CLI shares with Drush.
        foreach (array('yes', 'no', 'quiet') as $option) {
            if ($input->getOption($option)) {
                $drushArgs[] = "--$option";
            }
        }
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $drushArgs[] = "--debug";
        }
        elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $drushArgs[] = "--verbose";
        }

        $drushCommand = implode(' ', array_map('escapeshellarg', $drushArgs));

        // SSH method.
        if ($input->getOption('ssh')) {
            $environment = new Environment($this->environment);
            $sshUrl = $environment->getSshUrl();
            $command = 'ssh -qt ' . escapeshellarg($sshUrl)
              . ' ' . escapeshellarg('cd /app/public; drush ' . $drushCommand);
        }
        // Site alias method (default).
        else {
            $aliasGroup = isset($this->project['alias-group']) ? $this->project['alias-group'] : $this->project['id'];
            $alias = '@' . $aliasGroup . '.' . $this->environment['id'];
            $command = "drush $alias $drushCommand";
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $output->writeln("Running command: $command");
        }

        passthru($command, $return_var);
        return $return_var;
    }
}
