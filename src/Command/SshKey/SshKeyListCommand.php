<?php
namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeyListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('ssh-key:list')
            ->setAliases(['ssh-keys'])
            ->setDescription('Get a list of SSH keys in your account');
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $keys = $this->api()->getSshKeys();

        if (empty($keys)) {
            $this->stdErr->writeln(sprintf(
                'You do not yet have any SSH public keys in your %s account.',
                $this->config()->get('service.name')
            ));
        } else {
            $headers = ['ID', 'Title', 'Fingerprint', 'Local path'];
            $defaultColumns = ['ID', 'Title', 'Local path'];
            /** @var \Platformsh\Cli\Service\Table $table */
            $table = $this->getService('table');
            /** @var \Platformsh\Cli\Service\SshKey $sshKeyService */
            $sshKeyService = $this->getService('ssh_key');
            $rows = [];
            foreach ($keys as $key) {
                $row = [$key->key_id, $key->title, $key->fingerprint];
                $identity = $sshKeyService->findIdentityMatchingPublicKeys([$key->fingerprint]);
                $path = $identity ? $identity . '.pub' : '';
                if (!$identity && !$table->formatIsMachineReadable()) {
                    $path = '<comment>Not found</comment>';
                }
                $row[] = $path;
                $rows[] = $row;
            }
            if ($table->formatIsMachineReadable()) {
                $table->render($rows, $headers, $defaultColumns);

                return 0;
            }

            $this->stdErr->writeln("Your SSH keys are:");
            $table->render($rows, $headers, $defaultColumns);
        }

        $this->stdErr->writeln('');

        $executable = $this->config()->get('application.executable');
        $this->stdErr->writeln("Add a new SSH key with: <info>$executable ssh-key:add</info>");
        $this->stdErr->writeln("Delete an SSH key with: <info>$executable ssh-key:delete [id]</info>");

        return !empty($keys) ? 0 : 1;
    }
}
