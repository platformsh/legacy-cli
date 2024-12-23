<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\Snippeter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'self:install', description: 'Install or update CLI configuration files')]
class SelfInstallCommand extends CommandBase
{
    public const INSTALLED_FILENAME = 'self_installed';
    public function __construct(private readonly Config $config, private readonly Filesystem $filesystem, private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly Shell $shell, private readonly SubCommandRunner $subCommandRunner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
             ->addOption('shell-type', null, InputOption::VALUE_REQUIRED, 'The shell type for autocompletion (bash or zsh)');
        $this->setHiddenAliases(['local:install']);
        $cliName = $this->config->getStr('application.name');
        $this->setHelp(
            <<<EOT
                This command automatically installs shell configuration for the {$cliName},
                adding autocompletion support and handy aliases. Bash and ZSH are supported.
                EOT,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configDir = $this->config->getUserConfigDir();

        $this->stdErr->write('Copying resource files...');
        $requiredFiles = [
            'shell-config.rc' => 'shell-config.tmpl.rc',
            'shell-config-bash.rc' => 'shell-config-bash.tmpl.rc',
        ];
        if ($this->config->getStr('application.executable') === 'platform') {
            $requiredFiles['shell-config-bash.rc'] = 'shell-config-bash-direct.tmpl.rc';
        }
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        try {
            foreach ($requiredFiles as $destFile => $sourceFile) {
                if (($contents = file_get_contents(CLI_ROOT . DIRECTORY_SEPARATOR . $sourceFile)) === false) {
                    throw new \RuntimeException(sprintf('Failed to read file: %s', CLI_ROOT . '/' . $sourceFile));
                }
                // Remove lines beginning with ##.
                $contents = \preg_replace('/^##[^\n]*\n/m', '', $contents);
                // Replace configuration keys inside double curly brackets with
                // their values.
                $contents = \preg_replace_callback('/\{\{ ?([a-z\d_.-]+) ?}}/', fn($matches) => $this->config->getStr($matches[1]), (string) $contents);
                $fs->dumpFile($configDir . DIRECTORY_SEPARATOR . $destFile, $contents);
            }
        } catch (\Exception $e) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln($this->indentAndWrap($e->getMessage()));

            // We can't do anything without these files, so exit.
            return 1;
        }
        $this->stdErr->writeln(' <info>done</info>');
        $this->stdErr->writeln('');

        if (OsUtil::isWindows()) {
            $this->stdErr->write('Creating .bat executable...');
            $binDir = $configDir . DIRECTORY_SEPARATOR . 'bin';
            $binTarget = $this->config->getStr('application.executable');
            $batDestination = $binDir . DIRECTORY_SEPARATOR . $this->config->getStr('application.executable') . '.bat';
            $fs->dumpFile($batDestination, $this->generateBatContents($binTarget));
            $this->stdErr->writeln(' <info>done</info>');
            $this->stdErr->writeln('');
        }

        $manager = new Manager($this->config);
        if ($manager->isSupported()) {
            $this->stdErr->write('Installing credential helper...');
            if ($manager->isInstalled()) {
                $this->stdErr->writeln(' <info>done</info> (already installed)');
            } else {
                try {
                    $manager->install();
                    $this->stdErr->writeln(' <info>done</info>');
                } catch (\Exception $e) {
                    $this->stdErr->writeln(' <comment>failed</comment>');
                    $this->stdErr->writeln($this->indentAndWrap($e->getMessage()));
                }
            }
            $this->stdErr->writeln('');
        }

        $shellType = $input->getOption('shell-type');
        if ($shellType === null && getenv('SHELL') !== false) {
            $shellType = str_replace('.exe', '', basename(getenv('SHELL')));
            $this->io->debug('Detected shell type: ' . $shellType);
        }

        $this->stdErr->write('Setting up autocompletion...');
        try {
            $args = [];
            if ($shellType) {
                $args['shell'] = $shellType;
            }
            $buffer = new BufferedOutput();
            $exitCode = $this->subCommandRunner->run('completion', $args, $buffer);
            if ($exitCode === 0 && ($autoCompleteHook = $buffer->fetch())) {
                $fs->dumpFile($configDir . '/autocompletion.sh', $autoCompleteHook);
                $this->stdErr->writeln(' <info>done</info>');
            }
        } catch (\Throwable $e) {
            // If stdout is not a terminal, then we tried but
            // autocompletion probably isn't needed at all, as we are in the
            // context of some kind of automated build. So ignore the error.
            if (!$this->io->isTerminal(STDOUT)) {
                $this->stdErr->writeln(' <info>skipped</info> (not a terminal)');
            } elseif ($shellType === null) {
                $this->stdErr->writeln(' <info>skipped</info> (unsupported shell)');
            }
            // Otherwise, print the error and continue. The user probably
            // wants to know what went wrong, but autocompletion is still not
            // essential.
            else {
                $this->stdErr->writeln(' <comment>failed</comment>');
                $this->stdErr->writeln($this->indentAndWrap($e->getMessage()));
            }
        }
        $this->stdErr->writeln('');

        $shellConfigOverrideVar = $this->config->getStr('application.env_prefix') . 'SHELL_CONFIG_FILE';
        $shellConfigOverride = getenv($shellConfigOverrideVar);
        if ($shellConfigOverride === '') {
            $this->io->debug(sprintf('Shell config detection disabled via %s', $shellConfigOverrideVar));
            $shellConfigFile = false;
        } elseif ($shellConfigOverride !== false) {
            $fsService = $this->filesystem;
            if (!$fsService->canWrite($shellConfigOverride)) {
                throw new \RuntimeException(sprintf(
                    'File not writable: %s (defined in %s)',
                    $shellConfigOverride,
                    $shellConfigOverrideVar,
                ));
            }
            $this->io->debug(sprintf('Shell config file specified via %s', $shellConfigOverrideVar));
            $shellConfigFile = $shellConfigOverride;
        } else {
            $shellConfigFile = $this->findShellConfigFile($shellType);
        }

        // Windows command prompt (non-Bash) behavior.
        if ($shellConfigFile === false && OsUtil::isWindows()) {
            $binDir = $configDir . DIRECTORY_SEPARATOR . 'bin';
            if ($this->inPath($binDir)) {
                $this->markSelfInstalled($configDir);
                $this->stdErr->writeln($this->getRunAdvice('', $binDir, true, false));
                return 0;
            }

            // Attempt to add to the PATH automatically using "setx".
            $path = getenv('Path', true);
            $pathParts = $path !== false ? array_unique(array_filter(explode(';', $path))) : [];
            if ($path !== false && !empty($pathParts)) {
                $newPath = implode(';', $pathParts) . ';' . $binDir;
                $shell = $this->shell;
                $setPathCommand = 'setx PATH ' . OsUtil::escapeShellArg($newPath);
                if ($shell->execute($setPathCommand, timeout: 10) !== false) {
                    $this->markSelfInstalled($configDir);
                    $this->stdErr->writeln($this->getRunAdvice('', $binDir, true, true));
                    return 0;
                }
            }

            $this->stdErr->writeln('To set up the CLI, add this directory to your Path environment variable:');
            $this->stdErr->writeln(sprintf('<info>%s</info>', $binDir));
            $this->stdErr->writeln('Then open a new terminal to continue.');

            return 1;
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
        $rcDestination = $configDirRelative . DIRECTORY_SEPARATOR . 'shell-config.rc';
        $suggestedShellConfig = 'HOME=${HOME:-' . escapeshellarg($this->config->getHomeDirectory()) . '}';
        $suggestedShellConfig .= PHP_EOL . sprintf(
            'export PATH=%s:"$PATH"',
            '"$HOME/"' . escapeshellarg($configDirRelative . '/bin'),
        );
        $suggestedShellConfig .= PHP_EOL . sprintf(
            'if [ -f %1$s ]; then . %1$s; fi',
            '"$HOME/"' . escapeshellarg($rcDestination),
        );

        if ($shellConfigFile !== false && str_contains($currentShellConfig, $suggestedShellConfig)) {
            $this->stdErr->writeln('Already configured: <info>' . $this->getShortPath($shellConfigFile) . '</info>');
            $this->stdErr->writeln('');
            $this->markSelfInstalled($configDir);
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

        $appName = $this->config->getStr('application.name');
        $begin = '# BEGIN SNIPPET: ' . $appName . ' configuration' . PHP_EOL;
        $end = ' # END SNIPPET';
        $beginPattern = '/^' . preg_quote('# BEGIN SNIPPET:') . '[^\n]*' . preg_quote($appName) . '[^\n]*$/m';

        if ($shellConfigFile === false || !$modify) {
            if ($shellConfigFile !== false) {
                $this->stdErr->writeln(sprintf(
                    'To set up the CLI, add the following lines to: <comment>%s</comment>',
                    $shellConfigFile,
                ));
            } else {
                $this->stdErr->writeln(
                    'To set up the CLI, add the following lines to your shell configuration file:',
                );
            }

            $this->stdErr->writeln($begin . $suggestedShellConfig . $end);
            return 1;
        }

        $newShellConfig = (new Snippeter())->updateSnippet($currentShellConfig, $suggestedShellConfig, $begin, $end, $beginPattern);

        if (file_exists($shellConfigFile)) {
            copy($shellConfigFile, $shellConfigFile . '.cli.bak');
        }

        if (!file_put_contents($shellConfigFile, $newShellConfig)) {
            $this->stdErr->writeln(sprintf('Failed to write to configuration file: <error>%s</error>', $shellConfigFile));
            return 1;
        }

        if ($create) {
            $this->stdErr->writeln('Configuration file created successfully: <info>' . $this->getShortPath($shellConfigFile) . '</info>');
        } else {
            $this->stdErr->writeln('Configuration file updated successfully: <info>' . $this->getShortPath($shellConfigFile) . '</info>');
        }

        $this->markSelfInstalled($configDir);

        $this->stdErr->writeln('');
        $this->stdErr->writeln($this->getRunAdvice($shellConfigFile, $configDir . '/bin'));

        return 0;
    }

    /**
     * Writes a file to prevent future self-installation prompts.
     *
     * @param string $configDir
     */
    private function markSelfInstalled(string $configDir): void
    {
        $filename = $configDir . DIRECTORY_SEPARATOR . self::INSTALLED_FILENAME;
        if (!file_exists($filename)) {
            $fs = new \Symfony\Component\Filesystem\Filesystem();
            $fs->dumpFile($filename, (string) json_encode(['installed_at' => date('c')]));
        }
    }

    /**
     * @return string[]
     */
    private function getRunAdvice(string $shellConfigFile, string $binDir, ?bool $inPath = null, bool $newTerminal = false): array
    {
        $advice = [
            sprintf('To use the %s,%s run:', $this->config->getStr('application.name'), $newTerminal ? ' open a new terminal, and' : ''),
        ];
        if ($inPath === null) {
            $inPath = $this->inPath($binDir);
        }
        if (!$inPath) {
            $sourceAdvice = sprintf('    <info>source %s</info>', $this->formatSourceArg($shellConfigFile));
            $sourceAdvice .= ' # (make sure your shell does this by default)';
            $advice[] = $sourceAdvice;
        }
        $advice[] = sprintf('    <info>%s</info>', $this->config->getStr('application.executable'));

        return $advice;
    }

    /**
     * Checks if a directory is in the PATH.
     */
    private function inPath(string $dir): bool
    {
        $PATH = getenv('PATH');
        $realpath = realpath($dir);
        if (!$PATH || !$realpath) {
            return false;
        }

        return in_array($realpath, explode(OsUtil::isWindows() ? ';' : ':', $PATH));
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
    private function formatSourceArg(string $filename): string
    {
        $arg = $filename;

        // Replace the home directory with ~, if not on Windows.
        if (DIRECTORY_SEPARATOR !== '\\') {
            $realpath = realpath($filename);
            $homeDir = $this->config->getHomeDirectory();
            if ($realpath && str_starts_with($realpath, $homeDir)) {
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
     * Shortens a filename for display.
     */
    private function getShortPath(string $filename): string
    {
        if (getcwd() === dirname($filename)) {
            return basename($filename);
        }
        $homeDir = $this->config->getHomeDirectory();
        if (str_starts_with($filename, $homeDir)) {
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
    protected function findShellConfigFile(string|null $shellType): string|false
    {
        // Special handling for the .environment file on Platform.sh environments.
        $envPrefix = $this->config->getStr('service.env_prefix');
        if (getenv($envPrefix . 'PROJECT') !== false
            && getenv($envPrefix . 'APP_DIR') !== false
            && getenv($envPrefix . 'APP_DIR') === $this->config->getHomeDirectory()) {
            return getenv($envPrefix . 'APP_DIR') . '/.environment';
        }

        // Default to Bash filenames.
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

        // Use .zshrc on ZSH.
        if ($shellType === 'zsh' || (empty($shellType) && getenv('ZSH'))) {
            $candidates = ['.zshrc'];
        }

        // Pick the first of the candidate files that already exists.
        $homeDir = $this->config->getHomeDirectory();
        foreach ($candidates as $candidate) {
            if (file_exists($homeDir . DIRECTORY_SEPARATOR . $candidate)) {
                $this->io->debug('Found existing config file: ' . $homeDir . DIRECTORY_SEPARATOR . $candidate);

                return $homeDir . DIRECTORY_SEPARATOR . $candidate;
            }
        }

        if (!is_writable($homeDir)) {
            return false;
        }

        // If none of the files exist (yet), and the home directory is writable,
        // then create a new file based on the shell type.
        if ($shellType === 'bash') {
            if (OsUtil::isOsX()) {
                $this->io->debug('OS X: defaulting to ~/.bash_profile');

                return $homeDir . DIRECTORY_SEPARATOR . '.bash_profile';
            }
            $this->io->debug('Defaulting to ~/.bashrc');

            return $homeDir . DIRECTORY_SEPARATOR . '.bashrc';
        } elseif ($shellType === 'zsh') {
            $this->io->debug('Defaulting to ~/.zshrc');

            return $homeDir . DIRECTORY_SEPARATOR . '.zshrc';
        }

        return false;
    }

    /**
     * Indents and word-wraps a string.
     *
     * @param string $str
     * @param int $indent
     * @param int $width
     *
     * @return string
     */
    private function indentAndWrap(string $str, int $indent = 4, int $width = 75): string
    {
        $spaces = str_repeat(' ', $indent);
        $wrapped = wordwrap($str, $width - $indent, PHP_EOL);

        return $spaces . preg_replace('/\r\n|\r|\n/', '$0' . $spaces, $wrapped);
    }

    /**
     * Generates a .bat file for Windows compatibility.
     *
     * @param string $binTarget
     *
     * @return string
     */
    private function generateBatContents(string $binTarget): string
    {
        return "@ECHO OFF\r\n" .
            "setlocal DISABLEDELAYEDEXPANSION\r\n" .
            "SET BIN_TARGET=%~dp0/" . trim(OsUtil::escapeShellArg($binTarget), '"\'') . "\r\n" .
            "php \"%BIN_TARGET%\" %*\r\n";
    }
}
