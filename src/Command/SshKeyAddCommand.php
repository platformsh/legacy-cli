<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
                'The path to the ssh key'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $input->getArgument('path');
        if (empty($path)) {
            $output->writeln("<error>You must specify the path to the key.</error>");
            return 1;
        }
        if (!file_exists($path)) {
            $output->writeln("<error>Key not found.<error>");
            return 1;
        }

        $process = new Process('ssh-keygen -l -f ' . escapeshellarg($path));
        $process->run();
        if ($process->getExitCode() == 1) {
            $output->writeln("<error>The file does not contain a valid key.</error>");
            return 1;
        }

        $key = file_get_contents($path);
        $helper = $this->getHelper('question');
        $title = $helper->ask($input, $output, new Question('Enter a name for the key: '));

        $client = $this->getAccountClient();
        $client->createSshKey(array('title' => $title, 'value' => $key));

        $message = '<info>';
        $message .= "\nThe given key has been successfully added. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
