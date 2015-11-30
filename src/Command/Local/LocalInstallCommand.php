<?php
namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Command\PlatformCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LocalInstallCommand extends PlatformCommand
{

    protected function configure()
    {
        $this->setName('local:install')
          ->setDescription('Install CLI configuration files');
        $this->setHiddenInList();
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $homeDir = $this->getHomeDir();
        $configDir = $this->getConfigDir();

        $platformRc = file_get_contents(CLI_ROOT . '/platform.rc');
        if ($platformRc === false) {
            $this->stdErr->writeln(sprintf('Failed to read file: %s', CLI_ROOT . '/platform.rc'));
            return 1;
        }

        $platformRcDestination = $configDir . DIRECTORY_SEPARATOR . 'platform.rc';
        if (file_put_contents($platformRcDestination, $platformRc) === false) {
            $this->stdErr->writeln(sprintf('Failed to write file: %s', $platformRcDestination));
            return 1;
        }

        if (!$shellConfigFile = $this->findShellConfigFile($homeDir)) {
            $this->stdErr->writeln('Failed to find a shell configuration file.');
            return 1;
        }

        $shellConfig = file_get_contents($shellConfigFile);
        if (strpos($shellConfig, $configDir . "/bin") !== false) {
            $this->stdErr->writeln(sprintf('Already configured: %s', $shellConfigFile));
            return 0;
        }

        $shellConfig .= PHP_EOL . PHP_EOL
          . "# Automatically added by Platform.sh CLI installer" . PHP_EOL
          . "export PATH=\"$configDir/bin:\$PATH\"" . PHP_EOL
          . '. ' . escapeshellarg($platformRcDestination) . " 2>/dev/null" . PHP_EOL;
        if (!file_put_contents($shellConfigFile, $shellConfig)) {
            $this->stdErr->writeln(sprintf('Failed to modify configuration file: %s', $shellConfigFile));
            return 1;
        }

        $shortPath = $shellConfigFile;
        if (getcwd() === dirname($shellConfigFile)) {
            $shortPath = basename($shellConfigFile);
        }

        $this->stdErr->writeln('Start a new terminal to use the new configuration.');
        $this->stdErr->writeln('Or to use it now, type:');
        $this->stdErr->writeln('  <info>source ' . escapeshellarg($shortPath) . '</info>');

        return 0;
    }

    /**
     * Finds a shell configuration file for the user.
     *
     * @param string $homeDir
     *   The user's home directory.
     *
     * @return string|false
     *   The absolute path to an existing shell config file, or false on
     *   failure.
     */
    protected function findShellConfigFile($homeDir)
    {
        $candidates = ['.zshrc', '.bashrc', '.bash_profile', '.profile'];
        $shell = str_replace('/bin/', '', getenv('SHELL'));
        if (!empty($shell)) {
            array_unshift($candidates, '.' . $shell . 'rc');
        }
        foreach ($candidates as $candidate) {
            if (file_exists($homeDir . DIRECTORY_SEPARATOR . $candidate)) {
                return $homeDir . DIRECTORY_SEPARATOR . $candidate;
            }
        }

        return false;
    }
}
