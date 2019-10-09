<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
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
            ->addArgument('cmd', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'A command to pass to Drush', ['status']);
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addAppOption();
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Run "drush status" on the remote environment', 'status');
        $this->addExample('Enable the Overlay module on the remote environment', 'en overlay');
        $this->addExample('Get a one-time login link for name@example.com (use quotes for complex commands)', "'user-login --mail=name@example.com'");
    }

    public function isHidden()
    {
        // Hide this command in the list if the project is not Drupal.
        $projectRoot = $this->getProjectRoot();
        if ($projectRoot && !Drupal::isDrupal($projectRoot)) {
            return true;
        }

        return parent::isHidden();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $drushCommand = $input->getArgument('cmd');
        $drushCommand = implode(' ', array_map([OsUtil::class, 'escapePosixShellArg'], (array) $drushCommand));

        // Pass through options that the CLI shares with Drush.
        foreach (['yes', 'no', 'quiet'] as $option) {
            if ($input->getOption($option) && !preg_match('/\b' . preg_quote($option) . '\b/', $drushCommand)) {
                $drushCommand .= " --$option";
            }
        }
        if (!preg_match('/\b((verbose|debug|quiet)\b|-v)/', $drushCommand)) {
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG ) {
                $drushCommand .= " --debug";
            } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $drushCommand .= " --verbose";
            } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $drushCommand .= " --verbose";
            } elseif ($output->getVerbosity() == OutputInterface::VERBOSITY_QUIET) {
                $drushCommand .= " --quiet";
            }
        }

        $appContainer = $this->selectRemoteContainer($input, false);
        $host = $this->selectHost($input, false, $appContainer);
        $appName = $appContainer->getName();

        $selectedEnvironment = $this->getSelectedEnvironment();

        $deployment = $this->api()->getCurrentDeployment($selectedEnvironment);

        // Use the PLATFORM_DOCUMENT_ROOT environment variable, if set, to
        // determine the path to Drupal.
        /** @var \Platformsh\Cli\Service\RemoteEnvVars $envVarsService */
        $envVarsService = $this->getService('remote_env_vars');
        $documentRoot = $envVarsService->getEnvVar('DOCUMENT_ROOT', $host);
        if ($documentRoot !== '') {
            $drupalRoot = $documentRoot;
        } else {
            // Fall back to a combination of the document root (from the
            // deployment configuration) and the PLATFORM_APP_DIR variable.
            $appDir = $envVarsService->getEnvVar('APP_DIR', $host) ?: '/app';

            $remoteApp = $deployment->getWebApp($appName);
            $relativeDocRoot = AppConfig::fromWebApp($remoteApp)->getDocumentRoot();

            $drupalRoot = $appDir . '/' . $relativeDocRoot;
        }

        $columns = (new Terminal())->getWidth();

        $sshDrushCommand = "COLUMNS=$columns drush --root=" . OsUtil::escapePosixShellArg($drupalRoot);
        if ($siteUrl = $this->api()->getSiteUrl($selectedEnvironment, $appName, $deployment)) {
            $sshDrushCommand .= " --uri=" . OsUtil::escapePosixShellArg($siteUrl);
        }
        $sshDrushCommand .= ' ' . $drushCommand;

        return $host->runCommandDirect($sshDrushCommand);
    }
}
