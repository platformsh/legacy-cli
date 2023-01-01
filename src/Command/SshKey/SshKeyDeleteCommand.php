<?php
namespace Platformsh\Cli\Command\SshKey;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeyDeleteCommand extends SshKeyCommandBase
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
        $this->addExample('Delete the key 123', '123');
        $help = 'This command lets you delete SSH keys from your account.'
            . "\n\n" . $this->certificateNotice();
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getArgument('id');
        if (empty($id) || !is_numeric($id)) {
            $this->stdErr->writeln('<error>You must specify the ID of the SSH key to delete.</error>');
            $this->stdErr->writeln(
                'List your SSH keys with: <info>' . $this->config()->get('application.executable') . ' ssh-keys</info>'
            );

            return 1;
        }

        $key = $this->api()->getClient()
                    ->getSshKey($id);
        if (!$key) {
            $this->stdErr->writeln("SSH key not found: <error>$id</error>");

            return 1;
        }

        $key->delete();

        $this->stdErr->writeln(sprintf(
            'The SSH key <info>%s</info> has been deleted from your %s account.',
            $id,
            $this->config()->get('service.name')
        ));

        // Reset and warm the SSH keys cache.
        try {
            $this->api()->getSshKeys(true);
        } catch (\Exception $e) {
            // Suppress exceptions; we do not need the result of this call.
        }

        /** @var \Platformsh\Cli\Service\SshConfig $sshConfig */
        $sshConfig = $this->getService('ssh_config');
        $sshConfig->configureSessionSsh();

        return 0;
    }
}
