<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

class EnvironmentDrushCommand extends CommandBase
{
    protected static $defaultName = 'environment:drush';

    private $api;
    private $config;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        Api $api,
        Config $config,
        Selector $selector,
        Shell $shell,
        Ssh $ssh
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['drush'])
            ->setDescription('Run a drush command on the remote environment')
            ->addArgument('cmd', InputArgument::OPTIONAL, 'A command and arguments to pass to Drush', 'status');

        $definition = $this->getDefinition();
        $this->selector->addEnvironmentOption($definition);
        $this->selector->addProjectOption($definition);
        $this->ssh->configureInput($definition);

        $this->addExample('Run "drush status" on the remote environment');
        $this->addExample('Enable the Overlay module on the remote environment', "'en overlay'");
    }

    public function isHidden()
    {
        // Hide this command in the list if the project is not Drupal.
        $projectRoot = $this->selector->getProjectRoot();
        if ($projectRoot && !Drupal::isDrupal($projectRoot)) {
            return true;
        }

        return parent::isHidden();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

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

        $appName = $selection->getAppName();
        $selectedEnvironment = $selection->getEnvironment();
        $sshUrl = $selectedEnvironment->getSshUrl($appName);

        // Get the document root for the application, to find the Drupal root.
        $deployment = $this->api->getCurrentDeployment($selectedEnvironment);
        $remoteApp = $deployment->getWebApp($appName);
        $relativeDocRoot = AppConfig::fromWebApp($remoteApp)->getDocumentRoot();

        // Use the PLATFORM_DOCUMENT_ROOT environment variable, if set, to
        // determine the path to Drupal. Fall back to a combination of the known
        // document root and the PLATFORM_APP_DIR variable.
        $envPrefix = (string) $this->config->get('service.env_prefix');
        $appRoot = sprintf('${%sAPP_DIR:-/app}', $envPrefix);
        $drupalRoot = sprintf('${%sDOCUMENT_ROOT:-%s}', $envPrefix, $appRoot . '/' . $relativeDocRoot);

        $columns = (new Terminal())->getWidth();

        $sshDrushCommand = "COLUMNS=$columns drush --root=\"$drupalRoot\"";
        if ($siteUrl = $this->api->getSiteUrl($selectedEnvironment, $appName, $deployment)) {
            $sshDrushCommand .= " --uri=" . OsUtil::escapePosixShellArg($siteUrl);
        }
        $sshDrushCommand .= ' ' . $drushCommand;

        $command = $this->ssh->getSshCommand()
            . ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($sshDrushCommand);

        return $this->shell->executeSimple($command);
    }
}
