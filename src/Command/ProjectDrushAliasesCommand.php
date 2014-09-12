<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\ConfirmationQuestion;

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

        $this->loadConfig();
        $environments = $this->config['environments'][$project['id']];

        if ($new_group && $new_group != $current_group) {
            $questionHelper = $this->getHelper('question');
            $consoleOutput = new ConsoleOutput();
            $existing = shell_exec("drush site-alias --pipe --format=list @" . escapeshellarg($new_group) . " 2>/dev/null");
            if ($existing) {
                $question = new ConfirmationQuestion("The alias group @$new_group already exists. Overwrite? [y/N] ", false);
                if (!$questionHelper->ask($input, $consoleOutput, $question)) {
                    return;
                }
            }
            $project['alias-group'] = $new_group;
            $this->writeCurrentProjectConfig('alias-group', $new_group);
            $this->createDrushAliases($project, $environments);
            $output->writeln('Project aliases created, group: @' . $new_group);

            $drushDir = $this->getApplication()->getHomeDirectory() . '/.drush';
            $oldFile = $drushDir . '/' . $current_group . '.aliases.drushrc.php';
            if (file_exists($oldFile)) {
                $question = new ConfirmationQuestion("Delete old alias group @$current_group? [Y/n] ");
                if ($questionHelper->ask($input, $consoleOutput, $question)) {
                    unlink($oldFile);
                }
            }

            $current_group = $new_group;
        }
        elseif ($input->getOption('recreate')) {
            $this->createDrushAliases($project, $environments);
            $output->writeln("Project aliases recreated");
        }

        // Don't run expensive drush calls if they are not needed.
        if ($input->getOption('quiet')) {
            return;
        }

        $output->writeln("Aliases for <info>{$project['name']}</info> ({$project['id']}):");
        $aliases = shell_exec("drush site-alias --pipe --format=list @" . escapeshellarg($current_group) . " 2>/dev/null");
        foreach (explode("\n", $aliases) as $alias) {
            $output->writeln('    @' . $alias);
        }
    }
}
