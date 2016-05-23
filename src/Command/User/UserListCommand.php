<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Cli\Util\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('user:list')
            ->setAliases(['users'])
            ->setDescription('List project users');
        Table::addFormatOption($this->getDefinition());
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();

        $rows = [];
        $i = 0;
        $table = new Table($input, $output);
        foreach ($project->getUsers() as $user) {
            $account = $this->api->getAccount($user);
            $role = $user['role'];
            $weight = $i++;
            if ($project->owner === $user->id) {
                $weight = -1;
                if (!$table->formatIsMachineReadable()) {
                    $role .= ' (owner)';
                }
            }
            $rows[$weight] = [$account['email'], $account['display_name'], $role, $this->formatDatetime($account['created_at'])];
        }

        ksort($rows);

        $table->render(array_values($rows), ['Email Address', 'Name', 'Project role', 'Created at']);
        return 0;
    }

    protected function formatDatetime($datetime) {
        $formatter = new PropertyFormatter();
        return $formatter->format($datetime, 'created_at');
    }

}
