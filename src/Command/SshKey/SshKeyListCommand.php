<?php
namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\Table;
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
        Table::addFormatOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $keys = $this->getClient()
                     ->getSshKeys();

        if (empty($keys)) {
            $this->stdErr->writeln("You do not yet have any SSH public keys in your " . CLI_CLOUD_SERVICE . " account");
        } else {
            $table = new Table($input, $output);
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

        $this->stdErr->writeln("Add a new SSH key with: <info>" . CLI_EXECUTABLE . " ssh-key:add</info>");
        $this->stdErr->writeln("Delete an SSH key with: <info>" . CLI_EXECUTABLE . " ssh-key:delete [id]</info>");

        return !empty($keys) ? 0 : 1;
    }
}
