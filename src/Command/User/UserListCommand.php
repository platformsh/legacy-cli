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
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();

        $rows = [];
        $i = 0;
        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        foreach ($this->api()->getProjectAccesses($project) as $projectAccess) {
            $account = $this->api()->getAccount($projectAccess);
            $role = $projectAccess->role;
            $weight = $i++;
            if ($project->owner === $projectAccess->id) {
                $weight = -1;
                if (!$table->formatIsMachineReadable()) {
                    $role .= ' (owner)';
                }
            }
            $rows[$weight] = [$account['email'], $account['display_name'], $role, $projectAccess->id];
        }

        ksort($rows);

        $table->render(array_values($rows), ['Email address', 'Name', 'Project role', 'ID']);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln("To view a user's role(s), run: <info>$executable user:get [email]</info>");
            $this->stdErr->writeln("To change a user's role(s), run: <info>$executable user:add [email]</info>");
        }

        return 0;
    }
}
