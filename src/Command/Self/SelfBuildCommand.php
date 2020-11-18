<?php
namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfBuildCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $local = true;

    protected function configure()
    {
        $this
            ->setName('self:build')
            ->setDescription('Build a new package of the CLI')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The path to a private key')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'The output filename', $this->config()->get('application.executable') . '.phar')
            ->addOption('replace-version', null, InputOption::VALUE_OPTIONAL, 'Replace the version number in config.yaml')
            ->addOption('no-composer-rebuild', null, InputOption::VALUE_NONE, 'Skip rebuilding Composer dependencies');
    }

    public function isEnabled()
    {
        // You can't build a Phar from another Phar.
        return !extension_loaded('Phar') || !\Phar::running(false);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!file_exists(CLI_ROOT . '/vendor')) {
            $this->stdErr->writeln('Directory not found: <error>' . CLI_ROOT . '/vendor</error>');
            $this->stdErr->writeln('Cannot build from a global install');
            return 1;
        }

        /** @var \Platformsh\Cli\Service\Filesystem $fs */
        $fs = $this->getService('fs');

        $outputFilename = $input->getOption('output');
        if ($outputFilename && !$fs->canWrite($outputFilename)) {
            $this->stdErr->writeln("Not writable: <error>$outputFilename</error>");
            return 1;
        }

        $keyFilename = $input->getOption('key');
        if ($keyFilename && !file_exists($keyFilename)) {
            $this->stdErr->writeln("File not found: <error>$keyFilename</error>");
            return 1;
        }

        $boxConfig = [];

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $version = $this->config()->getVersion();
        if ($input->getOption('replace-version')) {
            $version = $input->getOption('replace-version');
        } else {
            $tag = $shell->execute(['git', 'describe', '--tags'], CLI_ROOT, false);
            if ($tag !== false) {
                $version = $tag;
            }
            $version = $questionHelper->askInput('Version', $version);
        }
        $boxConfig['replacements']['version-placeholder'] = $version;

        if (!$this->checkInstallerFile()) {
            return 1;
        }

        if ($outputFilename) {
            $boxConfig['output'] = $fs->makePathAbsolute($outputFilename);
            $phar = $boxConfig['output'];
        } else {
            // Default output: cli-VERSION.phar in the current directory.
            $boxConfig['output'] = getcwd() . '/cli-' . $version . '.phar';
            $phar = $boxConfig['output'];
        }
        if ($keyFilename) {
            $boxConfig['key'] = realpath($keyFilename);
        }

        if (file_exists($phar)) {
            if (!$questionHelper->confirm("File exists: <comment>$phar</comment>. Overwrite?")) {
                return 1;
            }
        }

        if (!$input->getOption('no-composer-rebuild')) {
            $this->stdErr->writeln('Ensuring correct composer dependencies.');
            $this->stdErr->writeln('If this fails, you may need to run "composer install" manually.');

            // Wipe the vendor directory to be extra sure.
            $shell->execute(['rm', '-rf', 'vendor'], CLI_ROOT, false);

            // We cannot use --no-dev, as that would exclude the
            // composer-bin-plugin tool.
            $shell->execute([
                'composer',
                'install',
                '--classmap-authoritative',
                '--no-interaction',
                '--no-progress',
            ], CLI_ROOT, true, false);

            // Install composer-bin-plugin dependencies.
            $shell->execute([
                'composer',
                'bin',
                'all',
                'install',
                '--no-interaction',
                '--no-progress',
            ], CLI_ROOT, true, false);
        }

        $boxArgs = [CLI_ROOT . '/vendor/bin/box', 'compile', '--no-interaction'];
        if ($output->isVeryVerbose()) {
            $boxArgs[] = '-vvv';
        } elseif ($output->isVerbose()) {
            $boxArgs[] = '-vv';
        } else {
            $boxArgs[] = '-v';
        }

        // Create a temporary box.json file for this build.
        if (!empty($boxConfig)) {
            $originalConfig = json_decode(file_get_contents(CLI_ROOT . '/box.json'), true);
            $boxConfig = array_merge($originalConfig, $boxConfig);
            $boxConfig['base-path'] = CLI_ROOT;
            $tmpJson = tempnam(sys_get_temp_dir(), 'cli-box-');
            file_put_contents($tmpJson, json_encode($boxConfig));
            $boxArgs[] = '--config=' . $tmpJson;
        }

        $this->stdErr->writeln('Building Phar package using Box');
        $shell->execute($boxArgs, CLI_ROOT, true, false);

        // Clean up the temporary file.
        if (!empty($tmpJson)) {
            unlink($tmpJson);
        }

        if (!file_exists($phar)) {
            $this->stdErr->writeln(sprintf('Build failed: file not found: <error>%s</error>', $phar));
            return 1;
        }

        $sha1 = sha1_file($phar);
        $sha256 = hash_file('sha256', $phar);
        $size = filesize($phar);

        $this->stdErr->writeln('The package was built successfully');
        $output->writeln($phar);
        $this->stdErr->writeln([
            sprintf('Size: %s', FormatterHelper::formatMemory($size)),
            sprintf('SHA-1: %s', $sha1),
            sprintf('SHA-256: %s', $sha256),
            sprintf('Version: %s', $version),
        ]);

        return 0;
    }

    /**
     * Ensure the installer.php file has config that matches config.yaml.
     *
     * @return bool
     */
    private function checkInstallerFile()
    {
        $installerFile = CLI_ROOT . '/dist/installer.php';
        $installerContents = \file_get_contents($installerFile);
        if ($installerContents === false) {
            $this->stdErr->writeln('Failed to read installer file: <error>' . $installerFile . '</error>');
            return false;
        }
        $start = "/* START_CONFIG */";
        $end = "/* END_CONFIG */";
        $startPos = \strpos($installerContents, $start) + \strlen($start);
        $endPos = \strpos($installerContents, $end);
        if ($startPos === false || $endPos === false || $endPos < $startPos) {
            $this->stdErr->writeln('Failed to locate config in installer file: <error>' . $installerFile . '</error>');
            return false;
        }
        $newConfig = \var_export([
            'envPrefix' => $this->config()->get('application.env_prefix'),
            'manifestUrl' => $this->config()->get('application.manifest_url'),
            'configDir' => $this->config()->get('application.user_config_dir'),
            'executable' => $this->config()->get('application.executable'),
            'cliName' => $this->config()->get('application.name'),
            'userAgent' => $this->config()->get('application.slug'),
        ], true);
        $newContents = \substr($installerContents, 0, $startPos) . $newConfig . \substr($installerContents, $endPos);
        if ($newContents !== $installerContents) {
            $this->stdErr->writeln('Modifying installer file to match config');
            if (!\file_put_contents($installerFile, $newContents)) {
                $this->stdErr->writeln('Failed to write to installer file: <error>' . $installerFile . '</error>');
                return false;
            }
        } else {
            $this->stdErr->writeln('Verified installer file');
        }
        return true;
    }
}
