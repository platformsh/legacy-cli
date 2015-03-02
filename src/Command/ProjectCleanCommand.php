<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Local\LocalBuild;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectCleanCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('project:clean')
          ->setAliases(array('clean'))
          ->setDescription('Remove old project builds')
          ->addOption(
            'keep',
            null,
            InputOption::VALUE_OPTIONAL,
            'The maximum number of builds to keep',
            5
          )
          ->addOption(
            'max-age',
            null,
            InputOption::VALUE_OPTIONAL,
            'The maximum age of builds, in seconds',
            86400
          )
          ->addOption(
            'include-active',
            null,
            InputOption::VALUE_NONE,
            'Delete active build(s) too'
          );
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        if (empty($projectRoot)) {
            $output->writeln("<error>You must run this command from a project folder.</error>");

            return;
        }

        $builder = new LocalBuild(array(), $output);
        $result = $builder->cleanBuilds(
          $projectRoot,
          $input->getOption('max-age'),
          $input->getOption('keep'),
          $input->getOption('include-active'),
          false
        );

        if (!$result[0] && !$result[1]) {
            $output->writeln("There are no builds to delete");
        } else {
            if ($result[0]) {
                $output->writeln("Deleted <info>{$result[0]}</info> build(s)");
            }
            if ($result[1]) {
                $output->writeln("Kept <info>{$result[1]}</info> build(s)");
            }
        }

        $archivesResult = $builder->cleanArchives($projectRoot);
        if ($archivesResult[0]) {
            $output->writeln("Deleted <info>{$archivesResult[0]}</info> archive(s)");
        }
    }

}
