<?php
namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Helper\Table;
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
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$projectRoot = $this->getProjectRoot()) {
            throw new RootNotFoundException();
        }

        $repository = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        $apps = LocalApplication::getApplications($repository);

        $rows = [];
        foreach ($apps as $app) {
            $rows[] = [$app->getName(), $app->getRoot()];
        }

        $table = new Table($output);
        $table->setHeaders(['Name or ID', 'Path']);
        $table->addRows($rows);
        $table->render();
    }
}
