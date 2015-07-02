<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfBuildCommand extends PlatformCommand
{
    protected function configure()
    {
        $this
          ->setName('self-build')
          ->setDescription('Build a new package of the CLI')
          ->addOption('key', null, InputOption::VALUE_OPTIONAL, 'The path to a private key')
          ->addOption('output', null, InputOption::VALUE_OPTIONAL, 'The output filename');
        $this->setHiddenInList();
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (extension_loaded('Phar') && \Phar::running(false)) {
            $this->stdErr->writeln('Cannot build a Phar from another Phar');
            return 1;
        }

        $outputFilename = $input->getOption('output');
        if ($outputFilename && !is_writable(dirname($outputFilename))) {
            $this->stdErr->writeln("Not writable: <error>$outputFilename</error>");
            return 1;
        }

        /** @var \Platformsh\Cli\Helper\ShellHelper $shellHelper */
        $shellHelper = $this->getHelper('shell');
        if (!$shellHelper->commandExists('box')) {
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

        $config = array();
        if ($outputFilename) {
            /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
            $fsHelper = $this->getHelper('fs');
            $config['output'] = $fsHelper->makePathAbsolute($outputFilename);
        }
        else {
            // Default output: platform.phar in the current directory.
            $cwd = getcwd();
            if ($cwd && $cwd !== CLI_ROOT) {
                $config['output'] = getcwd() . '/platform.phar';
            }
        }
        if ($keyFilename) {
            $config['key'] = realpath($keyFilename);
        }

        $phar = isset($config['output']) ? $config['output'] : CLI_ROOT . '/platform.phar';
        if (file_exists($phar)) {
            /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            if (!$questionHelper->confirm("File exists: <comment>$phar</comment>. Overwrite?", $input, $this->stdErr)) {
                return 1;
            }
        }

        $boxArgs = array('box', 'build', '--no-interaction');

        // Create a temporary box.json file for this build.
        if (!empty($config)) {
            $originalConfig = json_decode(file_get_contents(CLI_ROOT . '/box.json'), true);
            $config = array_merge($originalConfig, $config);
            $config['base-path'] = CLI_ROOT;
            $tmpJson = tempnam('/tmp', 'box_json');
            file_put_contents($tmpJson, json_encode($config));
            $boxArgs[] = '--configuration=' . $tmpJson;
        }

        $this->stdErr->writeln("Building Phar package using Box");
        $shellHelper->setOutput($output);
        $shellHelper->execute($boxArgs, CLI_ROOT, true, true);

        // Clean up the temporary file.
        if (!empty($tmpJson)) {
            unlink($tmpJson);
        }

        if (!file_exists($phar)) {
            $this->stdErr->writeln("File not found: <error>$phar</error>");
            return 1;
        }

        $sha1 = sha1_file($phar);
        $version = $this->getApplication()->getVersion();
        $size = filesize($phar);

        $output->writeln("Package built: <info>$phar</info>");
        $this->stdErr->writeln("  Size: " . number_format($size) . " B");
        $this->stdErr->writeln("  SHA1: $sha1");
        $this->stdErr->writeln("  Version: $version");
        return 0;
    }
}
