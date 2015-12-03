<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\PlatformCommand;
use Platformsh\Cli\Helper\ArgvHelper;
use Platformsh\Cli\Local\LocalApplication;
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
        $enabled = parent::isEnabled();

        // Hide this command in the list if the project is not Drupal.
        if ($enabled && isset($GLOBALS['argv'][1]) && $GLOBALS['argv'][1] === 'list') {
            $projectRoot = $this->getProjectRoot();
            if ($projectRoot) {
                return Drupal::isDrupal($projectRoot . '/' . LocalProject::REPOSITORY_DIR);
            }
        }

        return $enabled;
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

        $appName = $this->selectApp($input, function (LocalApplication $app) {
            return Drupal::isDrupal($app->getRoot());
        });

        $selectedEnvironment = $this->getSelectedEnvironment();
        $sshUrl = $selectedEnvironment->getSshUrl($appName);

        // Get the LocalApplication object for the specified application, if
        // available.
        $projectRoot = $this->getProjectRoot();
        if ($appName && $projectRoot && $this->selectedProjectIsCurrent() && is_dir($projectRoot . '/' . LocalProject::REPOSITORY_DIR)) {
            $apps = LocalApplication::getApplications($projectRoot . '/' . LocalProject::REPOSITORY_DIR);
            foreach ($apps as $possibleApp) {
                if ($possibleApp->getName() === $appName) {
                    $app = $possibleApp;
                    break;
                }
            }
        }

        // Use the local application configuration (if available) to determine
        // the correct Drupal root.
        if (isset($app) && isset($app->getConfig()['web']['document_root'])) {
            $documentRoot = trim($app->getConfig()['web']['document_root'], '/') ?: 'public';
            $drupalRoot = '/app/' . $documentRoot;
        }
        else {
            // Fall back to the PLATFORM_DOCUMENT_ROOT environment variable,
            // which is usually correct, except where the document_root was
            // specified as '/'.
            $drupalRoot = '${PLATFORM_DOCUMENT_ROOT:-/app/public}';

            if ($this->stdErr->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->stdErr->writeln('<comment>Warning:</comment> using $PLATFORM_DOCUMENT_ROOT for the Drupal root. This fails in cases where the document_root is /.');
            }
        }

        $dimensions = $this->getApplication()
                           ->getTerminalDimensions();
        $columns = $dimensions[0] ?: 80;

        $sshDrushCommand = "COLUMNS=$columns drush --root=\"$drupalRoot\"";
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
