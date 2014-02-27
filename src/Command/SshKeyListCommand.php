<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class SshKeyListCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('ssh-keys')
            ->setDescription('Get a list of all added SSH keys.');
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = $this->getAccountClient();
        $data = $client->getSshKeys();
        $key_rows = array();
        foreach ($data['keys'] as $key) {
            $key_row = array();
            $key_row[] = $key['id'];
            $key_row[] = $key['title'] . ' (' . $key['fingerprint'] . ')';
            $key_rows[] = $key_row;
        }

        $table = $this->getHelperSet()->get('table');
        $table
            ->setHeaders(array('ID', 'Key'))
            ->setRows($key_rows);
        $table->render($output);
        $output->writeln("\nYou can delete any key by running <info>platform ssh-key:delete [id]</info>.\n");
    }
}
