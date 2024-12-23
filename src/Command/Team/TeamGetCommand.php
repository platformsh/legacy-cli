<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Team;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'team:get', description: 'View a team')]
class TeamGetCommand extends TeamCommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->selector->addOrganizationOptions($this->getDefinition(), true);
        $this->addCompleter($this->selector);
        $this->addTeamOption()
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The name of a property to view');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $team = $this->validateTeamInput($input);
        if (!$team) {
            return 1;
        }
        $data = array_merge(array_flip(['id', 'label', 'organization_id', 'counts', 'project_permissions']), $team->getProperties());

        if ($input->getOption('property')) {
            $this->propertyFormatter->displayData($output, $data, $input->getOption('property'));
            return 0;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $organization = $this->api->getOrganizationById($team->organization_id);
            if ($organization) {
                $this->stdErr->writeln(\sprintf('Viewing the team %s in the organization %s', $this->getTeamLabel($team), $this->api->getOrganizationLabel($organization)));
            } else {
                $this->stdErr->writeln(\sprintf('Viewing the team %s', $this->getTeamLabel($team)));
            }
        }

        $headings = [];
        $values = [];
        foreach ($data as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->propertyFormatter->format($value, $key);
        }

        $this->table->renderSimple($values, $headings);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->getStr('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To add projects to the team, run: <info>%s team:project:add -t %s</info>', $executable, OsUtil::escapeShellArg($team->id)));
            $this->stdErr->writeln(\sprintf('To add a user to the team, run: <info>%s team:user:add -t %s</info>', $executable, OsUtil::escapeShellArg($team->id)));
        }

        return 0;
    }
}
