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
        if (empty($id) && $input->isInteractive()) {
            $keys = $this->api()->getSshKeys(true);
            if (empty($keys)) {
                $this->stdErr->writeln('You do not have any SSH keys in your account.');
                return 1;
            }
            $options = [];
            foreach ($keys as $key) {
                $options[$key->key_id] = sprintf('%s (%s)', $key->key_id, $key->title ?: $key->fingerprint);
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $id = $questionHelper->choose($options, 'Enter a number to choose a key to delete:', null, false);
        }
        if (empty($id) || !is_numeric($id)) {
            $this->stdErr->writeln('<error>You must specify the ID of the SSH key to delete.</error>');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(
                'List your SSH keys with: <comment>' . $this->config()->get('application.executable') . ' ssh-keys</comment>'
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
