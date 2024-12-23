<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\RemoteEnvVars;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

#[AsCommand(name: 'environment:drush', description: 'Run a drush command on the remote environment', aliases: ['drush'])]
class EnvironmentDrushCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly RemoteEnvVars $remoteEnvVars, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('cmd', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'A command to pass to Drush', ['status']);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Run "drush status" on the remote environment', 'status');
        $this->addExample('Enable the Overlay module on the remote environment', 'en overlay');
        $this->addExample('Get a one-time login link (using -- before options)', 'user-login -- --mail=name@example.com');
        $this->addExample('Alternative syntax (quoting the whole command)', "'user-login --mail=name@example.com'");
    }

    public function isHidden(): bool
    {
        if (parent::isHidden()) {
            return true;
        }

        // Hide this command in the list if the project is not Drupal.
        // Avoid checking if running in the home directory.
        $projectRoot = $this->selector->getProjectRoot();
        if ($projectRoot && $this->config->getHomeDirectory() !== getcwd() && !Drupal::isDrupal($projectRoot)) {
            return true;
        }

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $drushCommand = (array) $input->getArgument('cmd');
        if (count($drushCommand) === 1) {
            $drushCommand = reset($drushCommand);
        } else {
            $drushCommand = implode(' ', array_map(OsUtil::escapePosixShellArg(...), $drushCommand));
        }

        // Pass through options that the CLI shares with Drush.
        foreach (['yes', 'no', 'quiet'] as $option) {
            if ($input->getOption($option) && !preg_match('/\b' . preg_quote($option) . '\b/', (string) $drushCommand)) {
                $drushCommand .= " --$option";
            }
        }
        if (!preg_match('/\b((verbose|debug|quiet)\b|-v)/', (string) $drushCommand)) {
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $drushCommand .= " --debug";
            } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $drushCommand .= " --verbose";
            } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $drushCommand .= " --verbose";
            } elseif ($output->getVerbosity() == OutputInterface::VERBOSITY_QUIET) {
                $drushCommand .= " --quiet";
            }
        }

        $appContainer = $selection->getRemoteContainer();
        $host = $this->selector->getHostFromSelection($input, $selection);
        $appName = $appContainer->getName();

        $selectedEnvironment = $selection->getEnvironment();

        $deployment = $this->api->getCurrentDeployment($selectedEnvironment);
        $documentRoot = $this->remoteEnvVars->getEnvVar('DOCUMENT_ROOT', $host);
        if ($documentRoot !== '') {
            $drupalRoot = $documentRoot;
        } else {
            // Fall back to a combination of the document root (from the
            // deployment configuration) and the PLATFORM_APP_DIR variable.
            $appDir = $this->remoteEnvVars->getEnvVar('APP_DIR', $host) ?: '/app';

            $remoteApp = $deployment->getWebApp($appName);
            $relativeDocRoot = AppConfig::fromWebApp($remoteApp)->getDocumentRoot();

            $drupalRoot = $appDir . '/' . $relativeDocRoot;
        }

        $columns = (new Terminal())->getWidth();

        $sshDrushCommand = "COLUMNS=$columns drush --root=" . OsUtil::escapePosixShellArg($drupalRoot);
        if ($siteUrl = $this->api->getSiteUrl($selectedEnvironment, $appName, $deployment)) {
            $sshDrushCommand .= " --uri=" . OsUtil::escapePosixShellArg($siteUrl);
        }
        $sshDrushCommand .= ' ' . $drushCommand;

        return $host->runCommandDirect($sshDrushCommand);
    }
}
