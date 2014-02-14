<?php

namespace CommerceGuys\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class SshKeyAddCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('ssh-key:add')
            ->setDescription('Add a new SSH key.')
            ->addArgument(
                'key',
                InputArgument::OPTIONAL,
                'The path to the ssh key'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->hasConfiguration()) {
            $output->writeln("<error>Platform settings not initialized. Please run 'platform init'.</error>");
            return;
        }

        $key_filename = $input->getArgument('key');
        if (empty($key_filename)) {
            $output->writeln("<error>You must provide a path to the key.</error>");
            return;
        }
        if (!file_exists($key_filename)) {
            $output->writeln("<error>Key not found.<error>");
            return;
        }

        $key = file_get_contents($key_filename);
        $dialog = $this->getHelperSet()->get('dialog');
        $title = $dialog->ask($output, 'Enter a name for the key: ');

        $client = $this->getAccountClient();
        $client->createSshKey(array('title' => $title, 'value' => $key));

        $message = '<info>';
        $message = "\nThe given key has been successfuly added. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
