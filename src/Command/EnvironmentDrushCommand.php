<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Helper\ArgvHelper;
use CommerceGuys\Platform\Cli\Local\Toolstack\Drupal;
use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDrushCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:drush')
            ->setAliases(array('drush'))
            ->setDescription('Run a drush command on the remote environment')
            ->addArgument('cmd', InputArgument::OPTIONAL, 'A command and arguments to pass to Drush', 'status')
            ->addOption('ssh', null, InputOption::VALUE_NONE, 'Connect via SSH instead of a site alias')
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
        $this->ignoreValidationErrors();
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

        $drushCommand = (array) $input->getArgument('cmd');
        if ($input instanceof ArgvInput) {
            $helper = new ArgvHelper();
            $drushCommand = $helper->getPassedCommand($this, $input);
        }

        // Set a default Drush command.
        if (!$drushCommand) {
            $drushCommand = 'status';
        }

        // Pass through options that the CLI shares with Drush.
        foreach (array('yes', 'no', 'quiet') as $option) {
            if ($input->getOption($option)) {
                $drushCommand .= " --$option";
            }
        }
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $drushCommand .= " --debug";
        }
        elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $drushCommand .= " --verbose";
        }

        // SSH method.
        if ($input->getOption('ssh')) {
            $environment = new Environment($this->environment);
            $sshUrl = $environment->getSshUrl();

            // Add some options that would normally come from the site alias.
            $appRoot = '/app/public';
            $sshDrushCommand = "drush -r $appRoot";
            if ($environmentUrl = $environment->getLink('public-url')) {
                $sshDrushCommand .= " -l $environmentUrl";
            }
            $sshDrushCommand .= ' ' . $drushCommand;

            $command = 'ssh -qt ' . escapeshellarg($sshUrl)
              . ' ' . escapeshellarg($sshDrushCommand);
        }
        // Site alias method (default).
        else {
            $aliasGroup = isset($this->project['alias-group']) ? $this->project['alias-group'] : $this->project['id'];
            $alias = '@' . $aliasGroup . '.' . $this->environment['id'];
            $command = "drush $alias $drushCommand";
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $output->writeln("Running command: <info>$command</info>");
        }

        passthru($command, $return_var);
        return $return_var;
    }
}
