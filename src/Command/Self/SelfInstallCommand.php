<?php
namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SelfInstallCommand extends CommandBase
{
    protected $local = true;

    protected function configure()
    {
        $this->setName('self:install')
             ->setDescription('Install or update CLI configuration files');
        $this->setHiddenAliases(['local:install']);
        $cliName = $this->config()->get('application.name');
        $this->setHelp(<<<EOT
This command automatically installs shell configuration for the {$cliName},
adding autocompletion support and handy aliases. Bash and ZSH are supported.
EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configDir = $this->config()->getUserConfigDir();

        $shellConfig = file_get_contents(CLI_ROOT . '/shell-config.rc');
        if ($shellConfig === false) {
            $this->stdErr->writeln(sprintf('Failed to read file: %s', CLI_ROOT . '/shell-config.rc'));
            return 1;
        }

        $shellConfigDestination = $configDir . DIRECTORY_SEPARATOR . 'shell-config.rc';
        if (file_put_contents($shellConfigDestination, $shellConfig) === false) {
            $this->stdErr->writeln(sprintf('Failed to write file: %s', $shellConfigDestination));
            return 1;
        }

        $this->stdErr->writeln(sprintf('Successfully copied CLI configuration to: %s', $shellConfigDestination));

        if (!$shellConfigFile = $this->findShellConfigFile()) {
            $this->stdErr->writeln('Failed to find a shell configuration file.');
            return 1;
        }

        $this->stdErr->writeln(sprintf('Reading shell configuration file: %s', $shellConfigFile));

        $currentShellConfig = file_get_contents($shellConfigFile);
        if (strpos($currentShellConfig, $configDir . "/bin") !== false) {
            $this->stdErr->writeln(sprintf('Already configured: <info>%s</info>', $shellConfigFile));
            return 0;
        }

        $suggestedShellConfig = "export PATH=\"$configDir/bin:\$PATH\"" . PHP_EOL
            . '. ' . escapeshellarg($shellConfigDestination) . " 2>/dev/null";

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm('Do you want to update the file automatically?')) {
            $suggestedShellConfig = PHP_EOL
                . '# ' . $this->config()->get('application.name') . ' configuration'
                . PHP_EOL
                . $suggestedShellConfig;

            $this->stdErr->writeln(sprintf(
                'To set up the CLI, add the following lines to: <comment>%s</comment>',
                $shellConfigFile
            ));
            $this->stdErr->writeln(preg_replace('/^/m', '  ', $suggestedShellConfig));
            return 1;
        }

        $newShellConfig = rtrim($currentShellConfig, PHP_EOL)
            . PHP_EOL . PHP_EOL
            . '# Automatically added by the ' . $this->config()->get('application.name')
            . PHP_EOL . $suggestedShellConfig;

        copy($shellConfigFile, $shellConfigFile . '.cli.bak');

        if (!file_put_contents($shellConfigFile, $newShellConfig)) {
            $this->stdErr->writeln(sprintf('Failed to modify configuration file: %s', $shellConfigFile));
            return 1;
        }

        $shortPath = $shellConfigFile;
        if (getcwd() === dirname($shellConfigFile)) {
            $shortPath = basename($shellConfigFile);
        }
        if (strpos($shortPath, ' ')) {
            $shortPath = escapeshellarg($shortPath);
        }

        $this->stdErr->writeln("Updated successfully. Start a new terminal to use the new configuration.");
        $this->stdErr->writeln('Or to use it now, type:');
        $this->stdErr->writeln('  <info>source ' . $shortPath . '</info>');

        return 0;
    }

    /**
     * Finds a shell configuration file for the user.
     *
     * @return string|false
     *   The absolute path to an existing shell config file, or false on
     *   failure.
     */
    protected function findShellConfigFile()
    {
        $candidates = [
            '.bash_profile',
            '.bashrc',
        ];
        $shell = str_replace('/bin/', '', getenv('SHELL'));
        if ($shell === 'zsh') {
            array_unshift($candidates, '.zshrc');
            array_unshift($candidates, '.zprofile');
        }
        $homeDir = Filesystem::getHomeDirectory();
        foreach ($candidates as $candidate) {
            if (file_exists($homeDir . DIRECTORY_SEPARATOR . $candidate)) {
                return $homeDir . DIRECTORY_SEPARATOR . $candidate;
            }
        }

        return false;
    }
}
