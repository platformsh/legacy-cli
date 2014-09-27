<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Toolstack\DrupalApp;
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
            ->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'Change the alias group name.')
            ->addOption('pipe', 'p', InputOption::VALUE_NONE, 'Output the current group name (do nothing else).')
            ->setDescription('Determine the project\'s Drush aliases (if any).');
    }

    public function isLocal()
    {
      return TRUE;
    }

    public function isEnabled() {
        $projectRoot = $this->getProjectRoot();
        return $projectRoot && DrupalApp::detect($projectRoot . '/repository', array('projectRoot' => $projectRoot));
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

        // Get the list of environments.
        $environments = $this->getEnvironments($project, true);

        if ($new_group && $new_group != $current_group) {
            $questionHelper = $this->getHelper('question');
            $consoleOutput = new ConsoleOutput();
            $existing = $this->shellExec("drush site-alias --pipe --format=list @" . escapeshellarg($new_group));
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

        // Don't run expensive drush calls if they are not needed.
        if ($output->isQuiet()) {
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
