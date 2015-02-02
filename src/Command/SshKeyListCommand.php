<?php

namespace CommerceGuys\Platform\Cli\Command;

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
        $client = $this->getAccountClient();
        $data = $client->getSshKeys();

        if (!empty($data['keys'])) {
            $output->writeln("Your SSH keys are:");
            $table = new Table($output);
            $headers = array('ID', 'Title', 'Fingerprint');
            $rows = array();
            foreach ($data['keys'] as $key) {
                $rows[] = array($key['id'], $key['title'], $key['fingerprint']);
            }
            $table->setHeaders($headers);
            $table->addRows($rows);
            $table->render();
            $output->writeln('');
        }

        $output->writeln("Add a new SSH key by running <info>platform ssh-key:add [path]</info>");
        $output->writeln("Delete an SSH key by running <info>platform ssh-key:delete [id]</info>");
    }
}
