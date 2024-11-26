<?php
namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ssh-key:list', description: 'Get a list of SSH keys in your account', aliases: ['ssh-keys'])]
class SshKeyListCommand extends SshKeyCommandBase
{
    private $tableHeader = [
        'id' => 'ID',
        'title' => 'Title',
        'fingerprint' => 'Fingerprint',
        'path' => 'Local path'
    ];
    private $defaultColumns = ['id', 'title', 'path'];

    protected function configure()
    {
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);

        $help = 'This command lets you list SSH keys in your account.'
            . "\n\n" . $this->certificateNotice();
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = $this->api()->getSshKeys();

        if (empty($keys)) {
            $this->stdErr->writeln(sprintf(
                'You do not yet have any SSH public keys in your %s account.',
                $this->config()->get('service.name')
            ));
        } else {
            /** @var \Platformsh\Cli\Service\Table $table */
            $table = $this->getService('table');
            /** @var \Platformsh\Cli\Service\SshKey $sshKeyService */
            $sshKeyService = $this->getService('ssh_key');
            $rows = [];
            foreach ($keys as $key) {
                $row = ['id' => $key->key_id, 'title' => $key->title, 'fingerprint' => $key->fingerprint];
                $identity = $sshKeyService->findIdentityMatchingPublicKeys([$key->fingerprint]);
                $path = $identity ? $identity . '.pub' : '';
                if (!$identity && !$table->formatIsMachineReadable()) {
                    $path = '<comment>Not found</comment>';
                }
                $row['path'] = $path;
                $rows[] = $row;
            }
            if ($table->formatIsMachineReadable()) {
                $table->render($rows, $this->tableHeader, $this->defaultColumns);

                return 0;
            }

            $this->stdErr->writeln("Your SSH keys are:");
            $table->render($rows, $this->tableHeader, $this->defaultColumns);
        }

        $this->stdErr->writeln('');

        $executable = $this->config()->get('application.executable');
        $this->stdErr->writeln("Add a new SSH key with: <info>$executable ssh-key:add</info>");
        $this->stdErr->writeln("Delete an SSH key with: <info>$executable ssh-key:delete [id]</info>");

        $this->stdErr->writeln('');
        $this->stdErr->writeln($this->certificateNotice());

        return !empty($keys) ? 0 : 1;
    }
}
