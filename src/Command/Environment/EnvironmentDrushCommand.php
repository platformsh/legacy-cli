<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDrushCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:drush')
            ->setAliases(['drush'])
            ->setDescription('Run a drush command on the remote environment')
            ->addArgument('cmd', InputArgument::OPTIONAL, 'A command and arguments to pass to Drush', 'status');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addAppOption();
        $this->addExample('Run "drush status" on the remote environment');
        $this->addExample('Enable the Overlay module on the remote environment', "'en overlay'");
    }

    public function isHiddenInList()
    {
        // Hide this command in the list if the project is not Drupal.
        $projectRoot = $this->getProjectRoot();
        if ($projectRoot && !Drupal::isDrupal($projectRoot)) {
            return true;
        }

        return parent::isHiddenInList();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $drushCommand = $input->getArgument('cmd');

        $sshOptions = '';

        // Pass through options that the CLI shares with Drush and SSH.
        foreach (['yes', 'no', 'quiet'] as $option) {
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
        if ($projectRoot && $this->selectedProjectIsCurrent()) {
            $app = LocalApplication::getApplication($appName, $projectRoot, self::$config);
        }

        // Use the local application configuration (if available) to determine
        // the correct Drupal root.
        if (isset($app)) {
            $drupalRoot = '/app/' . $app->getDocumentRoot();
        }
        else {
            // Fall back to the PLATFORM_DOCUMENT_ROOT environment variable,
            // which is usually correct, except where the document_root was
            // specified as '/'.
            $documentRootEnvVar = self::$config->get('service.env_prefix') . 'DOCUMENT_ROOT';
            $drupalRoot = '${' . $documentRootEnvVar . ':-/app/public}';

            $this->debug('<comment>Warning:</comment> using $' . $documentRootEnvVar . ' for the Drupal root. This fails in cases where the document_root is /.');
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

        return $this->getHelper('shell')->executeSimple($command);
    }
}
