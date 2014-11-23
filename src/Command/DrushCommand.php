<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Local\Toolstack\Drupal;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DrushCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('drush')
            ->setDescription('Invoke a drush command using the site alias for the current environment.');
        $this->ignoreValidationErrors();
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
        // Try to autodetect the project and environment.
        // There is no point in allowing the user to override them
        // using --project and --environment, in that case they can run
        // drush by themselves and specify the site alias manually.
        $project = $this->getCurrentProject();
        if (!$project) {
            $output->writeln("<error>You must run this command from a project folder.</error>");
            return 1;
        }
        $environment = $this->getCurrentEnvironment($project);
        if (!$environment) {
            $output->writeln("<error>Could not determine the current environment.</error>");
            return 1;
        }

        $this->getHelper('drush')->ensureInstalled();

        $aliasGroup = isset($this->project['alias-group']) ? $this->project['alias-group'] : $this->project['id'];

        $alias = $aliasGroup . '.' . $environment['id'];
        $command = 'drush @' . $alias . ' ';
        // Take the entire input string (all arguments and options) after the
        // name of the drush command.
        if (!$input instanceof ArgvInput) {
            throw new \InvalidArgumentException('Invalid input type');
        }
        $command .= substr($input->__toString(), 6);

        passthru($command);
    }
}
