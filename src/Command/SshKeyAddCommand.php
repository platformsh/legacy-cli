<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

class SshKeyAddCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('ssh-key:add')
          ->setDescription('Add a new SSH key')
          ->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'The path to an existing SSH key. Leave blank to generate a new key'
          )
          ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'A name to identify the key');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $questionHelper = $this->getHelper('question');

        $path = $input->getArgument('path');
        if (empty($path)) {
            $shellHelper = $this->getHelper('shell');
            $default = $this->getDefaultKeyFilename();
            $defaultPath = "$default.pub";

            // Look for an existing local key.
            if (file_exists($defaultPath) && $questionHelper->confirm(
                "Use existing local key <info>" . basename($defaultPath) . "</info>?",
                $input,
                $output
              )
            ) {
                $path = $defaultPath;
            } // Offer to generate a key.
            elseif ($shellHelper->commandExists('ssh-keygen') && $shellHelper->commandExists(
                'ssh-add'
              ) && $questionHelper->confirm("Generate a new key?", $input, $output)
            ) {
                $newKey = $this->getNewKeyFilename($default);
                $args = array('ssh-keygen', '-t', 'rsa', '-f', $newKey, '-N', '');
                $shellHelper->execute($args, null, true);
                $path = "$newKey.pub";
                $this->stdErr->writeln("Generated a new key: $path");
                passthru('ssh-add ' . escapeshellarg($newKey));
            } else {
                $this->stdErr->writeln("<error>You must specify the path to a public SSH key</error>");

                return 1;
            }

        }

        if (!file_exists($path)) {
            $this->stdErr->writeln("File not found: <error>$path<error>");

            return 1;
        }

        $process = new Process('ssh-keygen -l -f ' . escapeshellarg($path));
        $process->run();
        if ($process->getExitCode() == 1) {
            $this->stdErr->writeln("The file does not contain a valid public key: <error>$path</error>");

            return 1;
        }

        $key = file_get_contents($path);

        $name = $input->getOption('name');
        if (!$name) {
            $name = $questionHelper->ask($input, $this->stdErr, new Question('Enter a name for the key: '));
        }

        $this->getClient()
             ->addSshKey($key, $name);

        $this->stdErr->writeln(
          'The SSH key <info>' . basename($path) . '</info> has been successfully added to your Platform.sh account'
        );

        return 0;
    }

    /**
     * The path to the user's key that we expect to be used with Platform.sh.
     *
     * @return string
     */
    protected function getDefaultKeyFilename()
    {
        $home = $this->getHelper('fs')
                     ->getHomeDirectory();

        return "$home/.ssh/platform_sh.key";
    }

    /**
     * Find the path for a new SSH key.
     *
     * If the file already exists, this will recurse to find a new filename.
     *
     * @param string $base
     * @param int    $number
     *
     * @return string
     */
    protected function getNewKeyFilename($base, $number = 1)
    {
        $base = $base ?: $this->getDefaultKeyFilename();
        $filename = $base;
        if ($number > 1) {
            $filename = strpos($base, '.key') ? str_replace('.key', ".$number.key", $base) : "$base.$number";
        }
        if (file_exists($filename)) {
            return $this->getNewKeyFilename($base, ++$number);
        }

        return $filename;
    }

}
