<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Util\PortUtil;
use Platformsh\Cli\Util\RelationshipsUtil;
use Platformsh\Cli\Util\Table;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class ProjectSshConfigCommand extends CommandBase
{

    protected $cmdConfig = null;

    protected function configure()
    {
        $this
            ->setName('project:ssh-config')
            ->addOption('alias', null, InputOption::VALUE_REQUIRED, 'Specify a custom alias the project')
            ->setAliases(['ssh-config'])
            ->setDescription('outputs OpenSSH valid configuration to connect all of the project environments');
        $this->addProjectOption();
        $this->addAppOption();
    }

    protected function loadCommandConfig() {
        $filename =self::$config->getUserConfigDir() . '/ssh-config.yaml';
        if (!isset($this->cmdConfig)) {
            $this->cmdConfig = [];
            if (file_exists($filename)) {
                $contents = file_get_contents($filename);
                $this->cmdConfig = Yaml::parse($contents);
            }
        }
    }

    protected function writeCommandConfig($filename) {
        $filename =self::$config->getUserConfigDir() . '/ssh-config.yaml';
        if (!empty($this->cmdConfig)) {
            $this->debug('Saving ssh-config confing to: ' . $filename);
            if (!file_put_contents($filename, Yaml::dump($this->cmdConfig, 10))) {
                throw new \RuntimeException('Failed to write ssh-config config to: ' . $filename);
            }
        }
    }

    /**
     * Automatically determine the best port for a new tunnel.
     *
     * @param int $default
     *
     * @return int
     */
    protected function getPort($project, $environment, $relationship, $default = 30000)
    {
        if (!isset($this->cmdConfig['ports'][$project][$environment][$relationship])) {
            $port = PortUtil::getPort(isset($this->cmdConfig['last']) ? $this->cmdConfig['last'] + 1 : $default);
            $this->cmdConfig['last'] = $port;
        }
        else
        {
            $port = $this->cmdConfig['ports'][$project][$environment][$relationship];
        }

        $this->cmdConfig['ports'][$project][$environment][$relationship] = $port;

        return $port;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadCommandConfig();

        $this->validateInput($input);
        $project = $this->getSelectedProject();

        // Always refresh to make sure we have the most up to date data
        $environments = $this->api()->getEnvironments($project, true);

        // Filter out inactive environments
        $environments = array_filter($environments, function ($environment) {
            return $environment->status !== 'inactive';
        });

        if (!$alias = $input->getOption('alias')) {
            $projectRoot = $this->getProjectRoot();
            if ($projectRoot) {
               $projectConfig = $this->localProject->getProjectConfig($projectRoot);
            }
            $alias = isset($projectConfig['alias-group']) ? $projectConfig['alias-group'] : $project->getProperty('id');
        }

        $appName = $this->selectApp($input);

        foreach ($environments as $environment) {
            if ($environment->hasLink('ssh')) {
                $sshUrl = $environment->getSshUrl($appName);
                $sshUrlParts = split("@", $sshUrl);
                $indent = str_repeat(' ', 2);

                $output->writeln("Host $alias.{$environment->id}");
                $output->writeln($indent . "Hostname {$sshUrlParts[0]}");
                $output->writeln($indent . "User {$sshUrlParts[1]}");

                $util = new RelationshipsUtil($this->stdErr);
                $relationships = $util->getRelationships($sshUrl);

                if ($relationships) {
                    foreach ($relationships as $relationship => $services) {
                        foreach ($services as $serviceKey => $service) {
                            $localPort = $this->getPort($project->getProperty('id'), $environment->id, $relationship);
                            $output->writeln($indent . "LocalForward $localPort {$service['host']}:{$service['port']}");
                        }
                    }
                }

                $output->writeln('');
            }
        }

        $this->writeCommandConfig();

        return 0;
    }
}
