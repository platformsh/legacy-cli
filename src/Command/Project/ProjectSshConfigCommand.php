<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Util\Table;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectSshConfigCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('project:ssh-config')
            ->addOption('alias', null, InputOption::VALUE_OPTIONAL, 'Specify a custom alias the project')
            ->setAliases(['ssh-config'])
            ->setDescription('outputs OpenSSH valid configuration to connect all of the project environments');
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();

        $environments = $this->api()->getEnvironments($this->getSelectedProject(), $refresh ? true : null);

        // Filter out inactive environments
        $environments = array_filter($environments, function ($environment) {
            return $environment->status !== 'inactive';
        });

        if (!$alias = $input->getOption('alias')) {
            $projectRoot = $this->getProjectRoot();
            if ($projectRoot) {
               $projectConfig = $this->localProject->getProjectConfig($projectRoot);
            }
            $alias = isset($projectConfig['alias-group']) ? $projectConfig['alias-group'] : $project->getProperty('id');;
        }

        foreach ($environments as $environment) {
            if ($environment->hasLink('ssh')) {
                $getLink = $environment->getLink('ssh');
                $sshUrl = parse_url($environment->getLink('ssh'));
                $indent = str_repeat(' ', 2);

                $output->writeln("Host $alias.{$environment->id}");
                $output->writeln($indent . "Hostname {$sshUrl['host']}");
                $output->writeln($indent . "User {$sshUrl['user']}");
                $output->writeln('');
            }
        }

        return 0;
    }
}
