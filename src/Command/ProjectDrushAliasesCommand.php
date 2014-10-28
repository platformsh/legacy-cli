<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;

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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->getCurrentProject();
        if (!$project) {
            throw new \Exception('This can only be run from inside a project directory');
        }

        $this->ensureDrushInstalled();

        $current_group = isset($project['alias-group']) ? $project['alias-group'] : $project['id'];

        if ($input->getOption('pipe')) {
            $output->writeln($current_group);
            return;
        }

        $new_group = ltrim($input->getOption('group'), '@');

        if ($new_group && $new_group != $current_group) {
            $existing = $this->shellExec("drush site-alias --pipe --format=list @" . escapeshellarg($new_group));
            if ($existing) {
                if (!$this->confirm("The alias group @$new_group already exists. Overwrite? [y/N] ", $input, $output, false)) {
                    return;
                }
            }
            $project['alias-group'] = $new_group;
            $this->writeCurrentProjectConfig('alias-group', $new_group);
            $environments = $this->getEnvironments($project);
            $this->createDrushAliases($project, $environments);
            $output->writeln('Project aliases created, group: @' . $new_group);

            $drushDir = $this->getApplication()->getHomeDirectory() . '/.drush';
            $oldFile = $drushDir . '/' . $current_group . '.aliases.drushrc.php';
            if (file_exists($oldFile)) {
                if (!$this->confirm("Delete old alias group @$current_group? [Y/n] ", $input, $output)) {
                    unlink($oldFile);
                }
            }

            // Clear the Drush cache now that the aliases have been updated.
            $this->shellExec('drush cache-clear drush');

            $current_group = $new_group;
        }
        elseif ($input->getOption('recreate')) {
            $environments = $this->getEnvironments($project);
            $this->createDrushAliases($project, $environments);
            $this->shellExec('drush cache-clear drush');
            $output->writeln("Project aliases recreated");
        }

        // Don't run expensive drush calls if they are not needed.
        if ($input->getOption('quiet')) {
            return;
        }

        $aliases = $this->shellExec("drush site-alias --pipe --format=list @" . escapeshellarg($current_group));
        if ($aliases) {
            $output->writeln("Aliases for <info>{$project['name']}</info> ({$project['id']}):");
            foreach (explode("\n", $aliases) as $alias) {
                $output->writeln('    @' . $alias);
            }
        }
    }

}
