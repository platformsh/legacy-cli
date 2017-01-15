<?php
namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LocalCleanCommand extends CommandBase
{
    protected $local = true;
    protected $hiddenInList = true;

    protected function configure()
    {
        $this
            ->setName('local:clean')
            ->setAliases(['clean'])
            ->setDescription('Remove old project builds')
            ->addOption(
                'keep',
                null,
                InputOption::VALUE_REQUIRED,
                'The maximum number of builds to keep',
                5
            )
            ->addOption(
                'max-age',
                null,
                InputOption::VALUE_REQUIRED,
                'The maximum age of builds, in seconds. Ignored if not set.'
            )
            ->addOption(
                'include-active',
                null,
                InputOption::VALUE_NONE,
                'Delete active build(s) too'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        /** @var \Platformsh\Cli\Local\LocalBuild $builder */
        $builder = $this->getService('local.build');
        $result = $builder->cleanBuilds(
            $projectRoot,
            $input->getOption('max-age'),
            $input->getOption('keep'),
            $input->getOption('include-active'),
            false
        );

        if (!$result[0] && !$result[1]) {
            $this->stdErr->writeln("There are no builds to delete");
        } else {
            if ($result[0]) {
                $this->stdErr->writeln("Deleted <info>{$result[0]}</info> build(s)");
            }
            if ($result[1]) {
                $this->stdErr->writeln("Kept <info>{$result[1]}</info> build(s)");
            }
        }

        $archivesResult = $builder->cleanArchives($projectRoot);
        if ($archivesResult[0]) {
            $this->stdErr->writeln("Deleted <info>{$archivesResult[0]}</info> archive(s)");
        }
    }
}
