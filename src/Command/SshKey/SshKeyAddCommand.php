<?php
namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Command\PlatformCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
          ->addOption('name', null, InputOption::VALUE_REQUIRED, 'A name to identify the key');
        $this->addExample('Add an existing public key', '~/.ssh/id_rsa.pub');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
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
              ) && $questionHelper->confirm("Generate a new key?", $input, $this->stdErr)
            ) {
                $newKey = $this->getNewKeyFilename($default);
                $args = array('ssh-keygen', '-t', 'rsa', '-f', $newKey, '-N', '');
                $shellHelper->execute($args, null, true);
                $this->stdErr->writeln("Generated a new key: $newKey.pub");
                $this->stdErr->writeln('Add this key to your SSH agent with:');
                $this->stdErr->writeln('    eval $(ssh-agent)');
                $this->stdErr->writeln('    ssh-add ' . escapeshellarg($newKey));
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
            $defaultName = gethostname() ?: null;
            $name = $questionHelper->askInput('Enter a name for the key', $input, $this->stdErr, $defaultName);
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
