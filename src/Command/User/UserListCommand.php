<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserListCommand extends CommandBase
{
    private $tableHeader = ['email' => 'Email address', 'Name', 'role' => 'Project role', 'ID'];

    protected function configure()
    {
        $this
            ->setName('user:list')
            ->setAliases(['users'])
            ->setDescription('List project users');
        Table::configureInput($this->getDefinition(), $this->tableHeader);
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
            $rows[$weight] = ['email' => $account['email'], $account['display_name'], 'role' => $role, $projectAccess->id];
        }

        ksort($rows);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Users on the project %s:',
                $this->api()->getProjectLabel($project)
            ));
        }

        $table->render(array_values($rows), $this->tableHeader);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln("To add a new user to the project, run: <info>$executable user:add [email]</info>");
            $this->stdErr->writeln('');
            $this->stdErr->writeln("To view a user's role(s), run: <info>$executable user:get [email]</info>");
            $this->stdErr->writeln("To change a user's role(s), run: <info>$executable user:update [email]</info>");
        }

        return 0;
    }
}
