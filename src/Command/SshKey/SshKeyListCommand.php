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
        $keys = $this->api()->getClient()
                     ->getSshKeys();

        if (empty($keys)) {
            $this->stdErr->writeln(sprintf(
                'You do not yet have any SSH public keys in your %s account.',
                $this->config()->get('service.name')
            ));
        } else {
            /** @var \Platformsh\Cli\Service\Table $table */
            $table = $this->getService('table');
            $headers = ['ID', 'Title', 'Fingerprint'];
            $rows = [];
            foreach ($keys as $key) {
                $rows[] = [$key['key_id'], $key['title'], $key['fingerprint']];
            }
            if ($table->formatIsMachineReadable()) {
                $table->render($rows, $headers);

                return 0;
            }

            $this->stdErr->writeln("Your SSH keys are:");
            $table->render($rows, $headers);
        }

        $this->stdErr->writeln('');

        $executable = $this->config()->get('application.executable');
        $this->stdErr->writeln("Add a new SSH key with: <info>$executable ssh-key:add</info>");
        $this->stdErr->writeln("Delete an SSH key with: <info>$executable ssh-key:delete [id]</info>");

        return !empty($keys) ? 0 : 1;
    }
}
