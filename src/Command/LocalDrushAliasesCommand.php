<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\DrushHelper;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LocalDrushAliasesCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('local:drush-aliases')
          ->setAliases(array('drush-aliases'))
          ->addOption('recreate', 'r', InputOption::VALUE_NONE, 'Recreate the aliases.')
          ->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'Recreate the aliases with a new group name.')
          ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the current group name (do nothing else).')
          ->setDescription('Find the project\'s Drush aliases');
        $this->addExample('Change the alias group to @example', '-g example');
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
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $projectConfig = LocalProject::getProjectConfig($projectRoot);
        $current_group = isset($projectConfig['alias-group']) ? $projectConfig['alias-group'] : $projectConfig['id'];

        if ($input->getOption('pipe') || !$this->isTerminal($output)) {
            $output->writeln($current_group);

            return 0;
        }

        $project = $this->getCurrentProject();

        $new_group = ltrim($input->getOption('group'), '@');

        $homeDir = $this->getHelper('fs')
                        ->getHomeDirectory();

        $drushHelper = new DrushHelper($output);
        $drushHelper->ensureInstalled();
        $drushHelper->setHomeDir($homeDir);

        $aliases = $drushHelper->getAliases($current_group);

        if (($new_group && $new_group != $current_group) || !$aliases || $input->getOption('recreate')) {
            $new_group = $new_group ?: $current_group;

            $this->stdErr->writeln("Creating Drush aliases in the group <info>@$new_group</info>");

            $questionHelper = $this->getHelper('question');
            if ($new_group != $current_group) {
                $existing = $drushHelper->getAliases($new_group);
                if ($existing && $new_group != $current_group) {
                    $question = "The Drush alias group <info>@$new_group</info> already exists. Overwrite?";
                    if (!$questionHelper->confirm($question, $input, $output, false)) {
                        return 1;
                    }
                }
                LocalProject::writeCurrentProjectConfig('alias-group', $new_group, $projectRoot);
            }

            $environments = $this->getEnvironments($project, true, false);
            $drushHelper->createAliases($project, $projectRoot, $environments, $current_group);

            if ($new_group != $current_group) {
                $drushDir = $homeDir . '/.drush';
                $oldFile = $drushDir . '/' . $current_group . '.aliases.drushrc.php';
                if (file_exists($oldFile)) {
                    if ($questionHelper->confirm("Delete old Drush alias group <info>@$current_group</info>?", $input, $this->stdErr)) {
                        unlink($oldFile);
                    }
                }
            }

            // Clear the Drush cache now that the aliases have been updated.
            $drushHelper->clearCache();

            // Read the new aliases.
            $aliases = $drushHelper->getAliases($new_group);
        }

        if ($aliases) {
            $this->stdErr->writeln("Drush aliases for <info>{$project->title}</info> ({$project->id}):");
            foreach (explode("\n", $aliases) as $alias) {
                $output->writeln('    @' . $alias);
            }
        }

        return 0;
    }
}
