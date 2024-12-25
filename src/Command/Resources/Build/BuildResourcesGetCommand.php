<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Resources\Build;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'resources:build:get', description: 'View the build resources of a project', aliases: ['build-resources:get', 'build-resources'])]
class BuildResourcesGetCommand extends CommandBase
{
    /** @var array<string, string> */
    protected array $tableHeader = [
        'cpu' => 'CPU',
        'memory' => 'Memory (MB)',
    ];

    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Table::configureInput($this->getDefinition(), $this->tableHeader);
        if ($this->config->has('service.resources_help_url')) {
            $this->setHelp('For more information on managing resources, see: <info>' . $this->config->getStr('service.resources_help_url') . '</info>');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        if (!$this->api->supportsSizingApi($selection->getProject())) {
            $this->stdErr->writeln(sprintf('The flexible resources API is not enabled for the project %s.', $this->api->getProjectLabel($selection->getProject(), 'comment')));
            return 1;
        }

        $project = $selection->getProject();
        $settings = $project->getSettings();

        $isOriginalCommand = $input instanceof ArgvInput;

        if (!$this->table->formatIsMachineReadable() && $isOriginalCommand) {
            $this->stdErr->writeln(sprintf('Build resources for the project %s:', $this->api->getProjectLabel($selection->getProject())));
        }

        $rows = [
            [
                'cpu' => $settings['build_resources']['cpu'],
                'memory' => $settings['build_resources']['memory'],
            ],
        ];

        $this->table->render($rows, $this->tableHeader);

        if (!$this->table->formatIsMachineReadable() && $isOriginalCommand) {
            $executable = $this->config->getStr('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('Configure resources by running: <info>%s resources:build:set</info>', $executable));
        }

        return 0;
    }
}
