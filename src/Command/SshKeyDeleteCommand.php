<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeyDeleteCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('ssh-key:delete')
            ->setDescription('Delete an SSH key')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'The ID of the SSH key to delete'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getArgument('id');
        if (empty($id) || !is_numeric($id)) {
            $output->writeln("<error>You must specify the ID of the key to delete</error>");
            return 1;
        }
        $client = $this->getAccountClient();
        $client->deleteSshKey(array('id' => $id));

        $output->writeln("The SSH key <info>#$id</info> has been deleted from your Platform.sh account");
        return 0;
    }
}
