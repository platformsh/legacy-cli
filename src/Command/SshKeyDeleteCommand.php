<?php

namespace Platformsh\Cli\Command;

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
            $this->stdErr->writeln("<error>You must specify the ID of the key to delete</error>");

            return 1;
        }

        $key = $this->getClient()
                    ->getSshKey($id);
        if (!$key) {
            $this->stdErr->writeln("SSH key not found: <error>$id</error>");
        }

        $key->delete();

        $this->stdErr->writeln("The SSH key <info>#$id</info> has been deleted from your Platform.sh account");

        return 0;
    }
}
