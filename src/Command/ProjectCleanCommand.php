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
                'number',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Number of builds to keep.',
                5
            );
            parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        if (empty($projectRoot)) {
            $output->writeln("<error>You must run this command from a project folder.</error>");
            return;
        }
        $project = $this->getCurrentProject();
        $environment = $this->getCurrentEnvironment($project);
        if (!$environment) {
            $output->writeln("<error>Could not determine the current environment.</error>");
            return;
        }
        $buildsDir = $projectRoot . '/builds';
        if ($this->dir_empty($buildsDir)) {
            $output->writeln("<error>There are no builds to clean.</error>");
            return;
        }

        // Collect directories.
        $builds = array();
        $handle = opendir($buildsDir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                $builds[] = $entry;
            }
        }

        // Remove old builds.
        sort($builds);
        $deleted = 0;
        $keep = (int) $input->getOption('number');
        foreach ($builds as $build) {
            if ((count($builds) - $deleted) > $keep) {
                $this->rmdir($projectRoot . '/builds/' . $build);
                $deleted++;
            }
        }

        $output->writeln('<info>Deleted ' . $deleted . ' old build(s).</info>');
    }

    /**
     * Check if directory contains files.
     *
     * @return boolean False if there are no files in directory.
     */
    private function dir_empty($dir) {
        if (!is_readable($dir)) return true;
        $handle = opendir($dir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                return false;
            }
        }
        return true;
    }
}
