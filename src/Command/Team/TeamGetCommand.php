<?php
namespace Platformsh\Cli\Command\Team;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TeamGetCommand extends TeamCommandBase
{

    protected function configure()
    {
        $this->setName('team:get')
            ->setDescription('View a team')
            ->addOrganizationOptions(true)
            ->addTeamOption()
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The name of a property to view');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $team = $this->validateTeamInput($input);
        if (!$team) {
            return 1;
        }
        $data = array_merge(array_flip(['id', 'label', 'organization_id', 'counts', 'project_permissions']), $team->getProperties());

        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        if ($input->getOption('property')) {
            $formatter->displayData($output, $data, $input->getOption('property'));
            return 0;
        }

        /** @var Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $organization = $this->api()->getOrganizationById($team->organization_id);
            if ($organization) {
                $this->stdErr->writeln(\sprintf('Viewing the team %s in the organization %s', $this->getTeamLabel($team), $this->api()->getOrganizationLabel($organization)));
            } else {
                $this->stdErr->writeln(\sprintf('Viewing the team %s', $this->getTeamLabel($team)));
            }
        }

        $headings = [];
        $values = [];
        foreach ($data as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $formatter->format($value, $key);
        }

        $table->renderSimple($values, $headings);

        if (!$table->formatIsMachineReadable()) {
            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To add projects to the team, run: <info>%s team:project:add -t %s</info>', $executable, OsUtil::escapeShellArg($team->id)));
            $this->stdErr->writeln(\sprintf('To add a user to the team, run: <info>%s team:user:add -t %s</info>', $executable, OsUtil::escapeShellArg($team->id)));
        }

        return 0;
    }
}
