<?php

namespace CommerceGuys\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class SshKeyDeleteCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('ssh-key:delete')
            ->setDescription('Manage SSH keys.')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'The id of the key to delete'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->hasConfiguration()) {
            $output->writeln("<error>Platform settings not initialized. Please run 'platform init'.</error>");
            return;
        }

        $id = $input->getArgument('id');
        if (empty($id)) {
            $output->writeln("<error>You must the ID of the key to delete.</error>");
            return;
        }
        $client = $this->getAccountClient();
        $client->deleteSshKey(array('id' => $id));

        $message = '<info>';
        $message = "\nThe SSH key #$id has been deleted. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
