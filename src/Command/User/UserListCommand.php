<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
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
        $table = $this->getService('table');
        foreach ($project->getUsers() as $user) {
            $account = $this->api()->getAccount($user);
            $role = $user['role'];
            $weight = $i++;
            if ($project->owner === $user->id) {
                $weight = -1;
                if (!$table->formatIsMachineReadable()) {
                    $role .= ' (owner)';
                }
            }
            $rows[$weight] = [$account['email'], $account['display_name'], $role];
        }

        ksort($rows);

        $table->render(array_values($rows), ['Email address', 'Name', 'Project role']);
        return 0;
    }

}
