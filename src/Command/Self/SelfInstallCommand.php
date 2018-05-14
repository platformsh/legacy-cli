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

        $rcFiles = [
            'shell-config.rc',
            'shell-config-bash.rc',
        ];
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        foreach ($rcFiles as $rcFile) {
            if (($rcContents = file_get_contents(CLI_ROOT . '/' . $rcFile)) === false) {
                $this->stdErr->writeln(sprintf('Failed to read file: %s', CLI_ROOT . '/' . $rcFile));

                return 1;
            }
            $fs->dumpFile($configDir . '/' . $rcFile, $rcContents);
        }

        $shellConfigFile = $this->findShellConfigFile();

        $currentShellConfig = '';

        if ($shellConfigFile !== false && file_exists($shellConfigFile)) {
            $this->stdErr->writeln(sprintf('Reading shell configuration file: %s', $shellConfigFile));

            $currentShellConfig = file_get_contents($shellConfigFile);
            if ($currentShellConfig === false) {
                $this->stdErr->writeln('Failed to read file');
                return 1;
            }
        }

        $configDirRelative = $this->config()->getUserConfigDir(false);
        $rcDestination = $configDirRelative . '/' . 'shell-config.rc';
        $suggestedShellConfig = sprintf(
            'export PATH=%s:"$PATH"',
            '"$HOME/"' . escapeshellarg($configDirRelative . '/bin')
        );
        $suggestedShellConfig .= PHP_EOL . sprintf(
            'if [ -f %1$s ]; then . %1$s; fi',
            '"$HOME/"' . escapeshellarg($rcDestination)
        );

        if (strpos($currentShellConfig, $suggestedShellConfig) !== false) {
            $this->stdErr->writeln(sprintf('Already configured: <info>%s</info>', $shellConfigFile));
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                "To use the %s, run:\n    <info>%s</info>",
                $this->config()->get('application.name'),
                $this->config()->get('application.executable')
            ));
            return 0;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if ($shellConfigFile === false || !$questionHelper->confirm('Do you want to update the file automatically?')) {
            $suggestedShellConfig = PHP_EOL
                . '# ' . $this->config()->get('application.name') . ' configuration'
                . PHP_EOL
                . $suggestedShellConfig;

            if ($shellConfigFile !== false) {
                $this->stdErr->writeln(sprintf(
                    'To set up the CLI, add the following lines to: <comment>%s</comment>',
                    $shellConfigFile
                ));
            } else {
                $this->stdErr->writeln(sprintf(
                    'To set up the CLI, add the following lines to your shell configuration file:',
                    $shellConfigFile
                ));
            }

            $this->stdErr->writeln(preg_replace('/^/m', '  ', $suggestedShellConfig));
            return 1;
        }

        $begin = '# BEGIN SNIPPET: Automatically added by the ' . $this->config()->get('application.name');
        $end = '# END SNIPPET';

        $beginPos = strpos($currentShellConfig, $begin);
        $endPos = strpos($currentShellConfig, $end, $beginPos ?: 0);
        if ($beginPos !== false && $endPos !== false && $endPos > $beginPos) {
            $newShellConfig = substr_replace(
                $currentShellConfig,
                $begin . PHP_EOL . $suggestedShellConfig . ' ' . $end,
                $beginPos,
                $endPos + strlen($end) - $beginPos
            );
        } else {
            $newShellConfig = rtrim($currentShellConfig, PHP_EOL)
                . PHP_EOL . PHP_EOL
                . $begin . PHP_EOL . $suggestedShellConfig . ' ' . $end
                . PHP_EOL;
        }

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

        $this->stdErr->writeln('Updated successfully.');
        $this->stdErr->writeln('');
        $this->stdErr->writeln([
            'To use the ' . $this->config()->get('application.name') . ', run:',
            '    <info>source ' . $shortPath . '</info> # (or start a new terminal)',
            '    <info>' . $this->config()->get('application.executable'),
        ]);

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
        // Special handling for the .environment file on Platform.sh environments.
        $envPrefix = $this->config()->get('service.env_prefix');
        if (getenv($envPrefix . 'PROJECT') !== false
            && getenv($envPrefix . 'APP_DIR') !== false
            && getenv($envPrefix . 'APP_DIR') === Filesystem::getHomeDirectory()) {
            return getenv($envPrefix . 'APP_DIR') . '/.environment';
        }

        $candidates = [
            '.bash_profile',
            '.bashrc',
        ];
        if (basename(getenv('SHELL')) === 'zsh' || getenv('ZSH')) {
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
