<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\SshKey;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ssh-key:list', description: 'Get a list of SSH keys in your account', aliases: ['ssh-keys'])]
class SshKeyListCommand extends SshKeyCommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'id' => 'ID',
        'title' => 'Title',
        'fingerprint' => 'Fingerprint',
        'path' => 'Local path',
    ];
    /** @var string[] */
    private array $defaultColumns = ['id', 'title', 'path'];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly SshKey $sshKey, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);

        $help = 'This command lets you list SSH keys in your account.'
            . "\n\n" . $this->certificateNotice($this->config);
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = $this->api->getSshKeys();

        if (empty($keys)) {
            $this->stdErr->writeln(sprintf(
                'You do not yet have any SSH public keys in your %s account.',
                $this->config->getStr('service.name'),
            ));
        } else {
            $table = $this->table;
            $sshKeyService = $this->sshKey;
            $rows = [];
            foreach ($keys as $key) {
                $row = ['id' => (string) $key->key_id, 'title' => $key->title, 'fingerprint' => $key->fingerprint];
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

        $executable = $this->config->getStr('application.executable');
        $this->stdErr->writeln("Add a new SSH key with: <info>$executable ssh-key:add</info>");
        $this->stdErr->writeln("Delete an SSH key with: <info>$executable ssh-key:delete [id]</info>");

        $this->stdErr->writeln('');
        $this->stdErr->writeln($this->certificateNotice($this->config));

        return !empty($keys) ? 0 : 1;
    }
}
