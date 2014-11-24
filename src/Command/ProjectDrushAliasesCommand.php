<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use CommerceGuys\Platform\Cli\Local\Toolstack\Drupal;

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
            ->setDescription('Determine and/or recreate the project\'s Drush aliases (if any).');
    }

    public function isLocal()
    {
      return TRUE;
    }

    public function isEnabled()
    {
        $projectRoot = $this->getProjectRoot();
        if ($projectRoot) {
            return Drupal::isDrupal($projectRoot . '/repository');
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

        Drupal::ensureDrushInstalled();

        $current_group = isset($project['alias-group']) ? $project['alias-group'] : $project['id'];

        if ($input->getOption('pipe') || !$this->isTerminal($output)) {
            $output->writeln($current_group);
            return 0;
        }

        $new_group = ltrim($input->getOption('group'), '@');

        $fsHelper = $this->getHelper('fs');
        $shellHelper = $this->getHelper('shell');

        $drushHelper = $this->getHelper('drush');
        $drushHelper->setHomeDir($fsHelper->getHomeDirectory());

        if ($new_group && $new_group != $current_group) {
            $questionHelper = $this->getHelper('question');
            $existing = $shellHelper->execute("drush site-alias --pipe --format=list @" . escapeshellarg($new_group));
            if ($existing) {
                if (!$questionHelper->confirm("The alias group <info>@$new_group</info> already exists. Overwrite?", $input, $output, false)) {
                    return 1;
                }
            }
            $project['alias-group'] = $new_group;
            $this->writeCurrentProjectConfig('alias-group', $new_group);
            $environments = $this->getEnvironments($project, true, false);
            $drushHelper->createAliases($project, $projectRoot, $environments);
            $output->writeln("Project aliases created, group: <info>@$new_group</info>");

            $drushDir = $fsHelper->getHomeDirectory() . '/.drush';
            $oldFile = $drushDir . '/' . $current_group . '.aliases.drushrc.php';
            if (file_exists($oldFile)) {
                if ($questionHelper->confirm("Delete old alias group <info>@$current_group</info>?", $input, $output)) {
                    unlink($oldFile);
                }
            }

            // Clear the Drush cache now that the aliases have been updated.
            $shellHelper->execute('drush cache-clear drush');

            $current_group = $new_group;
        }
        elseif ($input->getOption('recreate')) {
            $environments = $this->getEnvironments($project, true, false);
            $drushHelper->createAliases($project, $projectRoot, $environments);
            $shellHelper->execute('drush cache-clear drush');
            $output->writeln("Project aliases recreated");
        }

        // Don't run expensive drush calls if they are not needed.
        if ($input->getOption('quiet')) {
            return 0;
        }

        $aliases = $shellHelper->execute("drush site-alias --pipe --format=list @" . escapeshellarg($current_group));
        if ($aliases) {
            $output->writeln("Aliases for <info>{$project['name']}</info> ({$project['id']}):");
            foreach (explode("\n", $aliases) as $alias) {
                $output->writeln('    @' . $alias);
            }
        }
    }

}
