<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeysAddCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('ssh-keys:add')
            ->setDescription('Add a new SSH key.')
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'The path to the ssh public key file'
            )
            ->addArgument(
                'title',
                InputArgument::OPTIONAL,
                'a name for the key'
            );;
            parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $input->getArgument('path');
        $title = $input->getArgument('title');
        if (empty($path)) {
            $output->writeln("<error>You must specify the path to the key.</error>");
            return;
        }
        if (!file_exists($path)) {
            $output->writeln("<error>Key not found.<error>");
            return;
        }

        $key = file_get_contents($path);
        if (empty($title)) {
            $dialog = $this->getHelperSet()->get('dialog');
            $title = $dialog->ask($output, 'Enter a name for the key: ');
        }

        $client = $this->getAccountClient();
        $client->createSshKey(array('title' => $title, 'value' => $key));
        $message = '<info>';
        $message .= "\nThe given key has been successfuly added. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
