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

        if ($shellConfigFile !== false) {
            $this->stdErr->writeln(sprintf('Selected shell configuration file: <info>%s</info>', $shellConfigFile));
            if (file_exists($shellConfigFile)) {
                $currentShellConfig = file_get_contents($shellConfigFile);
                if ($currentShellConfig === false) {
                    $this->stdErr->writeln('Failed to read file.');
                    return 1;
                }
            }
        }

        $configDirRelative = $this->config()->getUserConfigDir(false);
        $rcDestination = $configDirRelative . '/' . 'shell-config.rc';
        $suggestedShellConfig = 'HOME=${HOME:-' . escapeshellarg(Filesystem::getHomeDirectory()) . '}';
        $suggestedShellConfig .= PHP_EOL . sprintf(
            'export PATH=%s:"$PATH"',
            '"$HOME/"' . escapeshellarg($configDirRelative . '/bin')
        );
        $suggestedShellConfig .= PHP_EOL . sprintf(
            'if [ -f %1$s ]; then . %1$s; fi',
            '"$HOME/"' . escapeshellarg($rcDestination)
        );

        if (strpos($currentShellConfig, $suggestedShellConfig) !== false) {
            $this->stdErr->writeln('Already configured.');
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
        $modify = false;
        if ($shellConfigFile !== false) {
            $confirmText = file_exists($shellConfigFile)
                ? 'Do you want to update the file automatically?'
                : 'Do you want to create the file automatically?';
            if ($questionHelper->confirm($confirmText)) {
                $modify = true;
            }
        }

        $appName = (string) $this->config()->get('application.name');
        $begin = '# BEGIN SNIPPET: ' . $appName . ' configuration';
        $end = '# END SNIPPET';

        if ($shellConfigFile === false || !$modify) {
            $suggestedShellConfig = PHP_EOL
                . $begin
                . PHP_EOL
                . $suggestedShellConfig
                . ' ' . $end;
            $this->stdErr->writeln('');

            if ($shellConfigFile !== false) {
                $this->stdErr->writeln(sprintf(
                    'To set up the CLI, add the following lines to: <comment>%s</comment>',
                    $shellConfigFile
                ));
            } else {
                $this->stdErr->writeln(
                    'To set up the CLI, add the following lines to your shell configuration file:'
                );
            }

            $this->stdErr->writeln($suggestedShellConfig);
            return 1;
        }

        // Look for the position of the $begin string in the current config.
        $beginPos = strpos($currentShellConfig, $begin);

        // Otherwise, look for a line that loosely matches the $begin string.
        if ($beginPos === false) {
            $beginPattern = '/^' . preg_quote('# BEGIN SNIPPET:') . '[^\n]*' . preg_quote($appName) . '[^\n]*$/m';
            if (preg_match($beginPattern, $currentShellConfig, $matches, PREG_OFFSET_CAPTURE)) {
                $beginPos = $matches[0][1];
            }
        }

        // Find the snippet's end: the first occurrence of $end after $begin.
        $endPos = false;
        if ($beginPos !== false) {
            $endPos = strpos($currentShellConfig, $end, $beginPos);
        }

        // If an existing snippet has been found, update it. Otherwise, add a
        // new snippet to the end of the file.
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
     *   The absolute path to a shell config file, or false on failure.
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

        // If none of the files exist (yet), then pick the first one.
        if (is_writable($homeDir)) {
            $filename = reset($candidates) ?: '.bash_profile';

            return $homeDir . DIRECTORY_SEPARATOR . $filename;
        }

        return false;
    }
}
