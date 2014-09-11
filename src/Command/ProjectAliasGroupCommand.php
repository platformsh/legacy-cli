<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectAliasGroupCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('project:alias-group')
            ->setAliases(array('alias-group'))
            ->addArgument('group', InputArgument::OPTIONAL, 'A new alias group name to set.')
            ->setDescription('Read or set the alias group name for this project.');
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

        $current_group = $project['id'];
        if (isset($project['alias-group'])) {
            $current_group = $project['alias-group'];
        }

        $new_group = $input->getArgument('group');
        $new_group = ltrim($new_group, '@');
        if ($new_group && $new_group != $current_group) {
          $dialog = $this->getHelperSet()->get('dialog');
          $existing = shell_exec("drush site-alias --pipe @" . escapeshellarg($new_group) . " 2>/dev/null");
          if ($existing) {
            $question = "The alias group @$new_group already exists. Overwrite? [y/N] ";
            if (!$dialog->askConfirmation($output, $question, false)) {
              return;
            }
          }
          $this->writeCurrentProjectConfig('alias-group', $new_group);
          $environments = $this->config['environments'][$project['id']];
          $this->createDrushAliases($project, $environments, $new_group);
          $output->writeln('Updated aliases for group @' . $new_group);

          $question = "Delete old alias group @$current_group? [y/N] ";
          if ($dialog->askConfirmation($output, $question, false)) {
              $application = $this->getApplication();
              $drushDir = $application->getHomeDirectory() . '/.drush';
              $filename = $drushDir . '/' . $current_group . '.aliases.drushrc.php';
              unlink($filename);
          }
        }
        elseif ($new_group == $current_group) {
            $output->writeln("The alias group is already @" . $current_group);
        }
        else {
          $output->writeln('@' . $current_group);
        }
    }
}
