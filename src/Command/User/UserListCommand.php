<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserListCommand extends CommandBase
{

    protected function configure()
    {
        $this
          ->setName('user:list')
          ->setAliases(array('users'))
          ->setDescription('List project users');
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();

        $rows = array();
        $i = 0;
        foreach ($project->getUsers() as $user) {
            $account = $this->getAccount($user);
            $role = $user['role'];
            $weight = $i++;
            if ($project->owner === $user->id) {
                $weight = -1;
                $role .= ' (owner)';
            }
            $rows[$weight] = array($account['email'], $account['display_name'], $role);
        }

        ksort($rows);

        $table = new Table($output);
        $table->setHeaders(array('Email address', 'Name', 'Role'));
        $table->setRows($rows);
        $table->render();
        return 0;
    }

}
