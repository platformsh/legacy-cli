<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeyListCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('ssh-key:list')
            ->setAliases(array('ssh-keys'))
            ->setDescription('Get a list of SSH keys in your account');
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $keys = $this->getClient()->getSshKeys();

        if (empty($keys)) {
            $output->writeln("You do not yet have any SSH public keys in your Platform.sh account");
        }
        else {
            $output->writeln("Your SSH keys are:");
            $table = new Table($output);
            $headers = array('ID', 'Title', 'Fingerprint');
            $rows = array();
            foreach ($keys as $key) {
                $rows[] = array($key['key_id'], $key['title'], $key['fingerprint']);
            }
            $table->setHeaders($headers);
            $table->addRows($rows);
            $table->render();
        }

        $output->writeln('');

        $output->writeln("Add a new SSH key by running <info>platform ssh-key:add [path]</info>");
        $output->writeln("Delete an SSH key by running <info>platform ssh-key:delete [id]</info>");
    }
}
