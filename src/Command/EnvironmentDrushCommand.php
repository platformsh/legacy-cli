<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Helper\ArgvHelper;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDrushCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('environment:drush')
          ->setAliases(array('drush'))
          ->setDescription('Run a drush command on the remote environment')
          ->addArgument('cmd', InputArgument::OPTIONAL, 'A command and arguments to pass to Drush', 'status');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addAppOption();
        $this->ignoreValidationErrors();
        $this->addExample('Run "drush status" on the remote environment');
        $this->addExample('Enable the Overlay module on the remote environment', "'en overlay'");
    }

    public function isEnabled()
    {
        $projectRoot = $this->getProjectRoot();
        if ($projectRoot) {
            return Drupal::isDrupal($projectRoot . '/' . LocalProject::REPOSITORY_DIR);
        }

        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        if ($input instanceof ArgvInput) {
            $helper = new ArgvHelper();
            $drushCommand = $helper->getPassedCommand($this, $input);
        }

        if (empty($drushCommand)) {
            $drushCommand = $input->getArgument('cmd');
        }

        $sshOptions = '';

        // Pass through options that the CLI shares with Drush and SSH.
        foreach (array('yes', 'no', 'quiet') as $option) {
            if ($input->getOption($option)) {
                $drushCommand .= " --$option";
            }
        }
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $drushCommand .= " --debug";
            $sshOptions .= ' -vv';
        } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $drushCommand .= " --verbose";
            $sshOptions .= ' -v';
        } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $drushCommand .= " --verbose";
        } elseif ($output->getVerbosity() == OutputInterface::VERBOSITY_QUIET) {
            $drushCommand .= " --quiet";
            $sshOptions .= ' -q';
        }

        $selectedEnvironment = $this->getSelectedEnvironment();
        $sshUrl = $selectedEnvironment->getSshUrl($input->getOption('app'));

        // The PLATFORM_DOCUMENT_ROOT environment variable is new. Default to
        // /app/public for backwards compatibility.
        $appRoot = '${PLATFORM_DOCUMENT_ROOT:-/app/public}';

        $dimensions = $this->getApplication()
                           ->getTerminalDimensions();
        $columns = $dimensions[0] ?: 80;

        $sshDrushCommand = "COLUMNS=$columns drush --root=\"$appRoot\"";
        if ($environmentUrl = $selectedEnvironment->getLink('public-url')) {
            $sshDrushCommand .= " --uri=" . escapeshellarg($environmentUrl);
        }
        $sshDrushCommand .= ' ' . $drushCommand . ' 2>&1';

        $command = 'ssh' . $sshOptions . ' ' . escapeshellarg($sshUrl)
          . ' ' . escapeshellarg($sshDrushCommand);

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->stdErr->writeln("Running command: <info>$command</info>");
        }

        passthru($command, $return_var);

        return $return_var;
    }
}
