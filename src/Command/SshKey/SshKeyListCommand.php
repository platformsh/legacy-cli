<?php
namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeyListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('ssh-key:list')
            ->setAliases(['ssh-keys'])
            ->setDescription('Get a list of SSH keys in your account');;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $keys = $this->getClient()
                     ->getSshKeys();

        if (empty($keys)) {
            $this->stdErr->writeln("You do not yet have any SSH public keys in your Platform.sh account");
        } else {
            $this->stdErr->writeln("Your SSH keys are:");
            $table = new Table($output);
            $headers = ['ID', 'Title', 'Fingerprint'];
            $rows = [];
            foreach ($keys as $key) {
                $rows[] = [$key['key_id'], $key['title'], $key['fingerprint']];
            }
            $table->setHeaders($headers);
            $table->addRows($rows);
            $table->render();
        }

        $this->stdErr->writeln('');

        $this->stdErr->writeln("Add a new SSH key by running <info>platform ssh-key:add [path]</info>");
        $this->stdErr->writeln("Delete an SSH key by running <info>platform ssh-key:delete [id]</info>");
    }
}
