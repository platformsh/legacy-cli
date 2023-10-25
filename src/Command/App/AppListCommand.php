<?php
namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppListCommand extends CommandBase
{
    private $tableHeader = ['Name', 'Type', 'disk' => 'Disk', 'Size', 'path' => 'Path'];
    private $defaultColumns = ['name', 'type'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('app:list')
            ->setAliases(['apps'])
            ->setDescription('List apps in the project')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a list of app names only');
        $this->addProjectOption()
            ->addEnvironmentOption();
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->chooseEnvFilter = $this->filterEnvsByState(['active']);
        $this->validateInput($input);

        // Find a list of deployed web apps.
        $deployment = $this->api()
            ->getCurrentDeployment($this->getSelectedEnvironment(), $input->getOption('refresh'));
        $apps = $deployment->webapps;

        if (!count($apps)) {
            $this->stdErr->writeln('No applications found.');
            $this->recommendOtherCommands($deployment);

            return 0;
        }

        if ($input->getOption('pipe')) {
            $appNames = array_keys($apps);
            sort($appNames, SORT_NATURAL);
            $output->writeln($appNames);

            return 0;
        }

        // Determine whether to show the "Local path" of the app. This will be
        // found via matching the remote, deployed app with one in the local
        // project.
        // @todo The "Local path" column is mainly here for legacy reasons, and can be removed in a future version.
        $showLocalPath = false;
        $localApps = [];
        if (($projectRoot = $this->getProjectRoot()) && $this->selectedProjectIsCurrent() && $this->config()->has('service.app_config_file')) {
            /** @var \Platformsh\Cli\Local\ApplicationFinder $finder */
            $finder = $this->getService('app_finder');
            $localApps = $finder->findApplications($projectRoot);
            $showLocalPath = true;
        }
        // Get the local path for a given application.
        $getLocalPath = function ($appName) use ($localApps) {
            foreach ($localApps as $localApp) {
                if ($localApp->getName() === $appName) {
                    return $localApp->getRoot();
                }
            }

            return '';
        };

        $headers = $this->tableHeader;
        $defaultColumns = $this->defaultColumns;
        if ($showLocalPath) {
            $headers['path'] = 'Path';
            $defaultColumns[] = 'path';
        }

        $rows = [];
        foreach ($apps as $app) {
            $row = [$app->name, $app->type, 'disk' => $app->disk, $app->size];
            if ($showLocalPath) {
                $row['path'] = $getLocalPath($app->name);
            }
            $rows[] = $row;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Applications on the project <info>%s</info>, environment <info>%s</info>:',
                $this->api()->getProjectLabel($this->getSelectedProject()),
                $this->api()->getEnvironmentLabel($this->getSelectedEnvironment())
            ));
        }

        $table->render($rows, $headers, $defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->recommendOtherCommands($deployment);
        }

        return 0;
    }

    private function recommendOtherCommands(EnvironmentDeployment $deployment)
    {
        $lines = [];
        $executable = $this->config()->get('application.executable');
        if ($deployment->services) {
            $lines[] = sprintf(
                'To list services, run: <info>%s services</info>',
                $executable
            );
        }
        if ($deployment->workers) {
            $lines[] = sprintf(
                'To list workers, run: <info>%s workers</info>',
                $executable
            );
        }
        if ($info = $deployment->getProperty('project_info', false)) {
            if (!empty($info['settings']['sizing_api_enabled']) && $this->config()->get('api.sizing') && $this->config()->isCommandEnabled('resources:set')) {
                $lines[] = sprintf(
                    "To configure resources, run: <info>%s resources:set</info>",
                    $executable
                );
            }
        }
        if ($lines) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln($lines);
        }
    }
}
