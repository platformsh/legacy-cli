<?php
namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppListCommand extends CommandBase
{

    protected static $defaultName = 'app:list';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['apps'])
            ->setDescription('List apps in the project')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        $this->addProjectOption()
            ->addEnvironmentOption();
        Table::configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        // Find a list of deployed web apps.
        $apps = $this->api()
            ->getCurrentDeployment($this->getSelectedEnvironment(), $input->getOption('refresh'))
            ->webapps;

        // Determine whether to show the "Local path" of the app. This will be
        // found via matching the remote, deployed app with one in the local
        // project.
        // @todo The "Local path" column is mainly here for legacy reasons, and can be removed in a future version.
        $showLocalPath = false;
        $localApps = [];
        if (($projectRoot = $this->getProjectRoot()) && $this->selectedProjectIsCurrent()) {
            $localApps = LocalApplication::getApplications($projectRoot, $this->config());
            $showLocalPath = true;
        }
        // Get the local path for a given application.
        $getLocalPath = function ($appName) use ($localApps) {
            foreach ($localApps as $localApp) {
                if ($localApp->getName() === $appName) {
                    return $localApp->getRoot();
                    break;
                }
            }

            return '';
        };

        $headers = ['Name', 'Type'];
        if ($showLocalPath) {
            $headers[] = 'Path';
        }

        $rows = [];
        foreach ($apps as $app) {
            $row = [$app->name, $app->type];
            if ($showLocalPath) {
                $row[] = $getLocalPath($app->name);
            }
            $rows[] = $row;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $table->render($rows, $headers);
    }
}
