<?php

namespace Platformsh\Cli\Command\Resources\Build;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BuildResourcesGetCommand extends CommandBase
{
    protected $tableHeader = [
        'cpu' => 'CPU',
        'memory' => 'Memory (MB)',
    ];

    protected function configure()
    {
        $this->setName('resources:build:get')
            ->setAliases(['build-resources:get', 'build-resources'])
            ->setDescription('View the build resources of a project')
            ->addProjectOption();
        Table::configureInput($this->getDefinition(), $this->tableHeader);
        if ($this->config()->has('service.resources_help_url')) {
            $this->setHelp('For more information on managing resources, see: <info>' . $this->config()->get('service.resources_help_url') . '</info>');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input);
        if (!$this->api()->supportsSizingApi($this->getSelectedProject())) {
            $this->stdErr->writeln(sprintf('The flexible resources API is not enabled for the project %s.', $this->api()->getProjectLabel($this->getSelectedProject(), 'comment')));
            return 1;
        }

        $project = $this->getSelectedProject();
        $settings = $project->getSettings();

        /** @var Table $table */
        $table = $this->getService('table');

        $isOriginalCommand = $input instanceof ArgvInput;

        if (!$table->formatIsMachineReadable() && $isOriginalCommand) {
            $this->stdErr->writeln(sprintf('Build resources for the project %s:', $this->api()->getProjectLabel($this->getSelectedProject())));
        }

        $rows = [
            [
                'cpu' => $settings['build_resources']['cpu'],
                'memory' => $settings['build_resources']['memory'],
            ],
        ];

        $table->render($rows, $this->tableHeader);

        if (!$table->formatIsMachineReadable() && $isOriginalCommand) {
            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('Configure resources by running: <info>%s resources:build:set</info>', $executable));
        }

        return 0;
    }
}
