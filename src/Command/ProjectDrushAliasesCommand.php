<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Helper\DrushHelper;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectDrushAliasesCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('project:drush-aliases')
          ->setAliases(array('drush-aliases'))
          ->addOption('recreate', 'r', InputOption::VALUE_NONE, 'Recreate the aliases.')
          ->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'Recreate the aliases with a new group name.')
          ->addOption('pipe', 'p', InputOption::VALUE_NONE, 'Output the current group name (do nothing else).')
          ->setDescription('Find the project\'s Drush aliases');
    }

    public function isLocal()
    {
        return true;
    }

    public function isEnabled()
    {
        $projectRoot = $this->getProjectRoot();
        if ($projectRoot) {
            return Drupal::isDrupal($projectRoot . '/' . LocalProject::REPOSITORY_DIR);
        }

        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->getCurrentProject();
        if (!$project) {
            throw new \Exception('This can only be run from inside a project directory');
        }

        $projectRoot = $this->getProjectRoot();

        $projectConfig = LocalProject::getProjectConfig($projectRoot);
        $current_group = isset($projectConfig['alias-group']) ? $projectConfig['alias-group'] : $project['id'];

        if ($input->getOption('pipe') || !$this->isTerminal($output)) {
            $output->writeln($current_group);

            return 0;
        }

        $new_group = ltrim($input->getOption('group'), '@');

        $homeDir = $this->getHelper('fs')
                        ->getHomeDirectory();

        $drushHelper = new DrushHelper($output);
        $drushHelper->ensureInstalled();
        $drushHelper->setHomeDir($homeDir);

        if ($new_group && $new_group != $current_group) {
            $questionHelper = $this->getHelper('question');
            $existing = $drushHelper->getAliases($new_group);
            if ($existing) {
                if (!$questionHelper->confirm(
                  "The alias group <info>@$new_group</info> already exists. Overwrite?",
                  $input,
                  $output,
                  false
                )
                ) {
                    return 1;
                }
            }
            $project['alias-group'] = $new_group;
            LocalProject::writeCurrentProjectConfig('alias-group', $new_group, $projectRoot);
            $output->write("Creating Drush aliases in the group <info>@$new_group</info>...");
            $environments = $this->getEnvironments($project, true, false);
            $drushHelper->createAliases($project, $projectRoot, $environments);
            $output->writeln(" done");

            $drushDir = $homeDir . '/.drush';
            $oldFile = $drushDir . '/' . $current_group . '.aliases.drushrc.php';
            if (file_exists($oldFile)) {
                if ($questionHelper->confirm("Delete old alias group <info>@$current_group</info>?", $input, $output)) {
                    unlink($oldFile);
                }
            }

            // Clear the Drush cache now that the aliases have been updated.
            $drushHelper->clearCache();

            $current_group = $new_group;
        } elseif ($input->getOption('recreate')) {
            $output->write("Recreating Drush aliases...");
            $environments = $this->getEnvironments($project, true, false);
            $drushHelper->createAliases($project, $projectRoot, $environments);
            $drushHelper->clearCache();
            $output->writeln(' done');
        }

        // Don't run expensive drush calls if they are not needed.
        if ($input->getOption('quiet')) {
            return 0;
        }

        $aliases = $drushHelper->getAliases($current_group);
        if ($aliases) {
            $output->writeln("Aliases for <info>{$project['name']}</info> ({$project['id']}):");
            foreach (explode("\n", $aliases) as $alias) {
                $output->writeln('    @' . $alias);
            }
        }

        return 0;
    }

}
