<?php
namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppListCommand extends CommandBase
{
    protected $local = true;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('app:list')
            ->setAliases(['apps'])
            ->setDescription('Get a list of all apps in the local repository');
        Table::configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$projectRoot = $this->getProjectRoot()) {
            throw new RootNotFoundException();
        }

        $apps = LocalApplication::getApplications($projectRoot, $this->config());

        $rows = [];
        foreach ($apps as $app) {
            $config = $app->getConfig();
            $type = isset($config['type']) ? $config['type'] : null;
            $rows[] = [$app->getName(), $type, $app->getRoot()];
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $table->render($rows, ['Name', 'Type', 'Path']);
    }
}
