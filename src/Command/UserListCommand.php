<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserListCommand extends PlatformCommand
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
        foreach ($project->getUsers() as $user) {
            $account = $user->getAccount();
            $rows[] = array($account['email'], $account['display_name'], $user['role']);
        }

        $table = new Table($output);
        $table->setHeaders(array('Email address', 'Name', 'Role'));
        $table->setRows($rows);
        $table->render();
        return 0;
    }

}
