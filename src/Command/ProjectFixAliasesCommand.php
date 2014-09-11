<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectFixAliasesCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('project:fix-aliases')
            ->setAliases(array('fix-aliases'))
            ->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'The alias group name.')
            ->setDescription('Forces the CLI to recreate the project\'s site (Drush) aliases, if any.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadConfig();

        $projectRoot = $this->getProjectRoot();
        if (empty($projectRoot)) {
          $output->writeln("<error>You must run this command from a project folder.</error>");
          return;
        }
        $project = $this->getCurrentProject();

        $group = $input->getOption('group');
        if ($group) {
          $existing = shell_exec("drush site-alias --pipe @" . escapeshellarg($group) . " 2>/dev/null");
          if ($existing) {
            $question = "The alias group already exists. Overwrite? [y/N] ";
            $dialog = $this->getHelperSet()->get('dialog');
            if (!$dialog->askConfirmation($output, $question, false)) {
              return;
            }
          }
        }

        $environments = $this->config['environments'][$project['id']];
        $this->createDrushAliases($project, $environments, $group);

        $output->writeln("Project aliases recreated.");
    }
}
