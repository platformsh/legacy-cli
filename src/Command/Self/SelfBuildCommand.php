<?php
namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Command\CommandBase;
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
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'The output filename')
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

        if (ini_get('phar.readonly')) {
            $this->stdErr->writeln('The <error>phar.readonly</error> PHP setting is enabled.');
            $this->stdErr->writeln('Disable it in your php.ini configuration.');
            return 1;
        }

        $outputFilename = $input->getOption('output');
        if ($outputFilename && !is_writable(dirname($outputFilename))) {
            $this->stdErr->writeln("Not writable: <error>$outputFilename</error>");
            return 1;
        }

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');
        if (!$shell->commandExists('box')) {
            $this->stdErr->writeln('Command not found: <error>box</error>');
            $this->stdErr->writeln('The Box utility is required to build new CLI packages. Try:');
            $this->stdErr->writeln('  composer global require kherge/box:~2.5');
            return 1;
        }

        $keyFilename = $input->getOption('key');
        if ($keyFilename && !file_exists($keyFilename)) {
            $this->stdErr->writeln("File not found: <error>$keyFilename</error>");
            return 1;
        }

        $boxConfig = [];
        if ($outputFilename) {
            /** @var \Platformsh\Cli\Service\Filesystem $fs */
            $fs = $this->getService('fs');
            $boxConfig['output'] = $fs->makePathAbsolute($outputFilename);
        } else {
            // Default output: CLI_PHAR in the current directory.
            $cwd = getcwd();
            if ($cwd && $cwd !== CLI_ROOT) {
                $boxConfig['output'] = getcwd() . '/' . $this->config()->get('application.phar');
            }
        }
        if ($keyFilename) {
            $boxConfig['key'] = realpath($keyFilename);
        }

        $phar = isset($boxConfig['output'])
            ? $boxConfig['output']
            : CLI_ROOT . '/' . $this->config()->get('application.phar');
        if (file_exists($phar)) {
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            if (!$questionHelper->confirm("File exists: <comment>$phar</comment>. Overwrite?")) {
                return 1;
            }
        }

        if (!$input->getOption('no-composer-rebuild')) {
            $this->stdErr->writeln('Ensuring correct composer dependencies');

            // Remove the 'vendor' directory, in case the developer has incorporated
            // their own version of dependencies locally.
            $shell->execute(['rm', '-r', 'vendor'], CLI_ROOT, true, false);

            $shell->execute([
                $shell->resolveCommand('composer'),
                'install',
                '--no-dev',
                '--classmap-authoritative',
                '--no-interaction',
                '--no-progress',
            ], CLI_ROOT, true, false);
        }

        $boxArgs = [$shell->resolveCommand('box'), 'build', '--no-interaction'];

        // Create a temporary box.json file for this build.
        if (!empty($boxConfig)) {
            $originalConfig = json_decode(file_get_contents(CLI_ROOT . '/box.json'), true);
            $boxConfig = array_merge($originalConfig, $boxConfig);
            $boxConfig['base-path'] = CLI_ROOT;
            $tmpJson = tempnam('/tmp', 'box_json');
            file_put_contents($tmpJson, json_encode($boxConfig));
            $boxArgs[] = '--configuration=' . $tmpJson;
        }

        $this->stdErr->writeln("Building Phar package using Box");
        $result = $shell->execute($boxArgs, CLI_ROOT, false, true);

        // Clean up the temporary file, regardless of errors.
        if (!empty($tmpJson)) {
            unlink($tmpJson);
        }

        if ($result === false) {
            return 1;
        }

        if (!file_exists($phar)) {
            $this->stdErr->writeln("File not found: <error>$phar</error>");
            return 1;
        }

        $sha1 = sha1_file($phar);
        $version = $this->config()->get('application.version');
        $size = filesize($phar);

        $output->writeln("Package built: <info>$phar</info>");
        $this->stdErr->writeln("  Size: " . number_format($size) . " B");
        $this->stdErr->writeln("  SHA1: $sha1");
        $this->stdErr->writeln("  Version: $version");
        return 0;
    }
}
