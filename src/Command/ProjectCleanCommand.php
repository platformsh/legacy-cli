<?php

namespace CommerceGuys\Platform\Cli\Command;

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
            ->setDescription('Remove project builds.')
            ->addOption(
                'keep',
                null,
                InputOption::VALUE_OPTIONAL,
                'Number of builds to keep.',
                5
            );
    }

    public function isLocal() {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        if (empty($projectRoot)) {
            $output->writeln("<error>You must run this command from a project folder.</error>");
            return;
        }

        $buildsDir = $projectRoot . '/builds';

        // Collect directories.
        $builds = array();
        $handle = opendir($buildsDir);
        while ($entry = readdir($handle)) {
            if (strpos($entry, '.') !== 0) {
                $builds[] = $entry;
            }
        }

        $count = count($builds);

        if (!$count) {
            $output->writeln("There are no builds to delete.");
            return;
        }

        // Remove old builds.
        sort($builds);
        $numDeleted = 0;
        $numKept = 0;
        $keep = (int) $input->getOption('keep');
        foreach ($builds as $build) {
            if ($count - $numDeleted > $keep) {
                $output->writeln("Deleting: $build");
                $this->rmdir($projectRoot . '/builds/' . $build);
                $numDeleted++;
            }
            else {
                $numKept++;
            }
        }

        if ($numDeleted) {
            $output->writeln("Deleted <info>$numDeleted</info> build(s).");
        }

        if ($numKept) {
            $output->writeln("Kept <info>$numKept</info> build(s).");
        }
    }

}
