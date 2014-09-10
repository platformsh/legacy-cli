<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeysDeleteCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('ssh-keys:delete')
            ->setDescription('Delete an SSH key.')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'The id of the key to delete'
            );
            parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getArgument('id');
        if (empty($id)) {
            $output->writeln("<error>You must specify the ID of the key to delete.</error>");
            return;
        }
        $client = $this->getAccountClient();
        $client->deleteSshKey(array('id' => $id));

        $message .= '<info>';
        $message .= "\nThe SSH key #$id has been deleted. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
