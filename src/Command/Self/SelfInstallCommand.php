<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SelfInstallCommand extends CommandBase
{
    protected static $defaultName = 'self:install';

    private $config;
    private $fs;
    private $questionHelper;

    public function __construct(
        Config $config,
        Filesystem $fs,
        QuestionHelper $questionHelper
    ) {
        $this->config = $config;
        $this->fs = $fs;
        $this->questionHelper = $questionHelper;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Install or update CLI configuration files');
        $this->setHiddenAliases(['local:install']);
        $cliName = $this->config->get('application.name');
        $this->setHelp(<<<EOT
This command automatically installs shell configuration for the {$cliName},
adding autocompletion support and handy aliases. Bash and ZSH are supported.
EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configDir = $this->config->getUserConfigDir();

        $rcFiles = [
            'shell-config.rc',
            'shell-config-bash.rc',
        ];
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        foreach ($rcFiles as $rcFile) {
            if (($rcContents = file_get_contents(CLI_ROOT . '/resources/' . $rcFile)) === false) {
                $this->stdErr->writeln(sprintf('Failed to read file: %s', CLI_ROOT . '/' . $rcFile));

                return 1;
            }
            $fs->dumpFile($configDir . '/' . $rcFile, $rcContents);
        }

        $shellConfigOverrideVar = $this->config->get('application.env_prefix') . 'SHELL_CONFIG_FILE';
        $shellConfigOverride = getenv($shellConfigOverrideVar);
        if ($shellConfigOverride === '') {
            $this->debug(sprintf('Shell config detection disabled via %s', $shellConfigOverrideVar));
            $shellConfigFile = false;
        } elseif ($shellConfigOverride !== false) {
            if (!$this->fs->canWrite($shellConfigOverride)) {
                throw new \RuntimeException(sprintf(
                    'File not writable: %s (defined in %s)',
                    $shellConfigOverride,
                    $shellConfigOverrideVar
                ));
            }
            $this->debug(sprintf('Shell config file specified via %s', $shellConfigOverrideVar));
            $shellConfigFile = $shellConfigOverride;
        } else {
            $shellConfigFile = $this->findShellConfigFile();
        }

        $currentShellConfig = '';

        if ($shellConfigFile !== false) {
            $this->stdErr->writeln(sprintf('Selected shell configuration file: <info>%s</info>', $this->getShortPath($shellConfigFile)));
            if (file_exists($shellConfigFile)) {
                $currentShellConfig = file_get_contents($shellConfigFile);
                if ($currentShellConfig === false) {
                    $this->stdErr->writeln('Failed to read file: <error>' . $shellConfigFile . '</error>');
                    return 1;
                }
            }
            $this->stdErr->writeln('');
        }

        $configDirRelative = $this->config->getUserConfigDir(false);
        $rcDestination = $configDirRelative . '/' . 'shell-config.rc';
        $suggestedShellConfig = 'HOME=${HOME:-' . escapeshellarg($this->fs->getHomeDirectory()) . '}';
        $suggestedShellConfig .= PHP_EOL . sprintf(
            'export PATH=%s:"$PATH"',
            '"$HOME/"' . escapeshellarg($configDirRelative . '/bin')
        );
        $suggestedShellConfig .= PHP_EOL . sprintf(
            'if [ -f %1$s ]; then . %1$s; fi',
            '"$HOME/"' . escapeshellarg($rcDestination)
        );

        if (strpos($currentShellConfig, $suggestedShellConfig) !== false) {
            $this->stdErr->writeln('Already configured: <info>' . $this->getShortPath($shellConfigFile) . '</info>');
            $this->stdErr->writeln('');

            $this->stdErr->writeln($this->getRunAdvice($shellConfigFile, $configDir . '/bin'));
            return 0;
        }

        $modify = false;
        $create = false;
        if ($shellConfigFile !== false) {
            $confirmText = file_exists($shellConfigFile)
                ? 'Do you want to update the file automatically?'
                : 'Do you want to create the file automatically?';
            if ($this->questionHelper->confirm($confirmText)) {
                $modify = true;
                $create = !file_exists($shellConfigFile);
            }
            $this->stdErr->writeln('');
        }

        $appName = (string) $this->config->get('application.name');
        $begin = '# BEGIN SNIPPET: ' . $appName . ' configuration';
        $end = '# END SNIPPET';

        if ($shellConfigFile === false || !$modify) {
            $suggestedShellConfig = PHP_EOL
                . $begin
                . PHP_EOL
                . $suggestedShellConfig
                . ' ' . $end;
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
            $newShellConfig = rtrim($currentShellConfig, PHP_EOL);
            if (strlen($newShellConfig)) {
                $newShellConfig .= PHP_EOL . PHP_EOL;
            }
            $newShellConfig .= $begin
                . PHP_EOL . $suggestedShellConfig . ' ' . $end
                . PHP_EOL;
        }

        if (file_exists($shellConfigFile)) {
            copy($shellConfigFile, $shellConfigFile . '.cli.bak');
        }

        if (!file_put_contents($shellConfigFile, $newShellConfig)) {
            $this->stdErr->writeln(sprintf('Failed to write to configuration file: %s', $shellConfigFile));
            return 1;
        }

        if ($create) {
            $this->stdErr->writeln('Configuration file created successfully: <info>' . $this->getShortPath($shellConfigFile) . '</info>');
        } else {
            $this->stdErr->writeln('Configuration file updated successfully: <info>' . $this->getShortPath($shellConfigFile) . '</info>');
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln($this->getRunAdvice($shellConfigFile, $configDir . '/bin'));

        return 0;
    }

    /**
     * @param string $shellConfigFile
     * @param string $binDir
     *
     * @return string[]
     */
    private function getRunAdvice($shellConfigFile, $binDir)
    {
        $advice = [
            sprintf('To use the %s, run:', $this->config->get('application.name'))
        ];
        if (!$this->inPath($binDir)) {
            $sourceAdvice = sprintf('    <info>source %s</info>', $this->formatSourceArg($shellConfigFile));
            $sourceAdvice .= ' # (make sure your shell does this by default)';
            $advice[] = $sourceAdvice;
        }
        $advice[] = sprintf('    <info>%s</info>', $this->config->get('application.executable'));

        return $advice;
    }

    /**
     * Check if a directory is in the PATH.
     *
     * @param string $dir
     *
     * @return bool
     */
    private function inPath($dir)
    {
        $PATH = getenv('PATH');
        $realpath = realpath($dir);
        if (!$PATH || !$realpath) {
            return false;
        }

        return in_array($realpath, explode(':', $PATH));
    }

    /**
     * Transform a filename into an argument for the 'source' command.
     *
     * This is only shown as advice to the user.
     *
     * @param string $filename
     *
     * @return string
     */
    private function formatSourceArg($filename)
    {
        $arg = $filename;

        // Replace the home directory with ~, if not on Windows.
        if (DIRECTORY_SEPARATOR !== '\\') {
            $realpath = realpath($filename);
            $homeDir = Filesystem::getHomeDirectory();
            if ($realpath && strpos($realpath, $homeDir) === 0) {
                $arg = '~/' . ltrim(substr($realpath, strlen($homeDir)), '/');
            }
        }

        // Ensure the argument isn't a basename ('source' would look it up in
        // the PATH).
        if ($arg === basename($filename)) {
            $arg = '.' . DIRECTORY_SEPARATOR . $filename;
        }

        // Crude argument escaping (escapeshellarg() would prevent tilde
        // substitution).
        return str_replace(' ', '\\ ', $arg);
    }

    /**
     * Shorten a filename for display.
     *
     * @param string $filename
     *
     * @return string
     */
    private function getShortPath($filename)
    {
        if (getcwd() === dirname($filename)) {
            return basename($filename);
        }
        $homeDir = Filesystem::getHomeDirectory();
        if (strpos($filename, $homeDir) === 0) {
            return str_replace($homeDir, '~', $filename);
        }

        return $filename;
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
        $envPrefix = $this->config->get('service.env_prefix');
        if (getenv($envPrefix . 'PROJECT') !== false
            && getenv($envPrefix . 'APP_DIR') !== false
            && getenv($envPrefix . 'APP_DIR') === Filesystem::getHomeDirectory()) {
            return getenv($envPrefix . 'APP_DIR') . '/.environment';
        }

        $shell = null;
        if (getenv('SHELL') !== false) {
            $shell = basename(getenv('SHELL'));
            $this->debug('Detected shell: ' . $shell);
        }

        $candidates = [
            '.bashrc',
            '.bash_profile',
        ];

        // OS X ignores .bashrc if .bash_profile is present.
        if (OsUtil::isOsX()) {
            $candidates = [
                '.bash_profile',
                '.bashrc',
            ];
        }

        if ($shell === 'zsh' || getenv('ZSH')) {
            array_unshift($candidates, '.zshrc');
            array_unshift($candidates, '.zprofile');
        }

        $homeDir = Filesystem::getHomeDirectory();
        foreach ($candidates as $candidate) {
            if (file_exists($homeDir . DIRECTORY_SEPARATOR . $candidate)) {
                $this->debug('Found existing config file: ' . $homeDir . DIRECTORY_SEPARATOR . $candidate);

                return $homeDir . DIRECTORY_SEPARATOR . $candidate;
            }
        }

        // If none of the files exist (yet), and we are on Bash, and the home
        // directory is writable, then use ~/.bashrc or ~/.bash_profile on
        // OS X.
        if (is_writable($homeDir) && $shell === 'bash') {
            if (OsUtil::isOsX()) {
                $this->debug('OS X: defaulting to ~/.bash_profile');

                return $homeDir . DIRECTORY_SEPARATOR . '.bash_profile';
            }
            $this->debug('Defaulting to ~/.bashrc');

            return $homeDir . DIRECTORY_SEPARATOR . '.bashrc';
        }

        return false;
    }
}
