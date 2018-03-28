<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

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
        Ssh::configureInput($this->getDefinition());
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

        // Pass through options that the CLI shares with Drush.
        foreach (['yes', 'no', 'quiet'] as $option) {
            if ($input->getOption($option)) {
                $drushCommand .= " --$option";
            }
        }
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $drushCommand .= " --debug";
        } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $drushCommand .= " --verbose";
        } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $drushCommand .= " --verbose";
        } elseif ($output->getVerbosity() == OutputInterface::VERBOSITY_QUIET) {
            $drushCommand .= " --quiet";
        }

        $appName = $this->selectApp($input);

        $selectedEnvironment = $this->getSelectedEnvironment();
        $sshUrl = $selectedEnvironment->getSshUrl($appName);

        // Get the document root for the application, to find the Drupal root.
        $deployment = $this->api()->getCurrentDeployment($selectedEnvironment);
        $remoteApp = $deployment->getWebApp($appName);
        $relativeDocRoot = AppConfig::fromWebApp($remoteApp)->getDocumentRoot();

        // Use the PLATFORM_DOCUMENT_ROOT environment variable, if set, to
        // determine the path to Drupal. Fall back to a combination of the known
        // document root and the PLATFORM_APP_DIR variable.
        $envPrefix = (string) $this->config()->get('service.env_prefix');
        $appRoot = sprintf('${%sAPP_DIR:-/app}', $envPrefix);
        $drupalRoot = sprintf('${%sDOCUMENT_ROOT:-%s}', $envPrefix, $appRoot . '/' . $relativeDocRoot);

        $columns = (new Terminal())->getWidth();

        $sshDrushCommand = "COLUMNS=$columns drush --root=\"$drupalRoot\"";
        if ($siteUrl = $this->api()->getSiteUrl($selectedEnvironment, $appName, $deployment)) {
            $sshDrushCommand .= " --uri=" . escapeshellarg($siteUrl);
        }
        $sshDrushCommand .= ' ' . $drushCommand;

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $command = $ssh->getSshCommand()
            . ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($sshDrushCommand);

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        return $shell->executeSimple($command);
    }
}
