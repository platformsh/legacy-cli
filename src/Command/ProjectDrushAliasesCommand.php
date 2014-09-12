<?php

namespace CommerceGuys\Platform\Cli\Command;

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
            ->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'The alias group name.')
            ->setDescription('Recreate the project\'s Drush aliases (if any).');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->getCurrentProject();
        if (!$project) {
            throw new \Exception('This can only be run from inside a project directory');
        }

        $current_group = isset($project['alias-group']) ? $project['alias-group'] : $project['id'];
        $new_group = $input->getOption('group');
        $new_group = ltrim($new_group, '@');

        $this->loadConfig();
        $environments = $this->config['environments'][$project['id']];

        if ($new_group && $new_group != $current_group) {
            $dialog = $this->getHelperSet()->get('dialog');
            $existing = shell_exec("drush site-alias --pipe @" . escapeshellarg($new_group) . " 2>/dev/null");
            if ($existing) {
                $question = "The alias group @$new_group already exists. Overwrite? [y/N] ";
                if (!$dialog->askConfirmation($output, $question, false)) {
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
                $question = "Delete old alias group @$current_group? [y/N] ";
                if ($dialog->askConfirmation($output, $question, false)) {
                    unlink($oldFile);
                }
            }
        }
        else {
            $this->createDrushAliases($project, $environments);
            $output->writeln("Project aliases recreated, group: @$current_group");
        }
    }
}
