<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectSshConfigCommand extends CommandBase
{
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

    /**
     * Automatically determine the best port for a new tunnel.
     *
     * @param string $project      Project ID
     * @param string $environment  Environment name
     * @param string $relationship Relationship name
     * @param array  &$cache       Cache
     *
     * @return int
     */
    protected function getPort($project, $environment, $relationship, array &$cache)
    {
        if (isset($cache[$project][$environment][$relationship])) {
            return $cache[$project][$environment][$relationship];
        }

        $port = PortUtil::getPort(isset($cache['last']) ? $cache['last'] + 1 : 30000);
        $cache['last'] = $port;

        $cache[$project][$environment][$relationship] = $port;

        return $port;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
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
                /** @var \Platformsh\Cli\Local\LocalProject $localProject */
                $localProject = $this->getService('local.project');
                $projectConfig = $localProject->getProjectConfig($projectRoot);
            }
            $alias = isset($projectConfig['alias-group']) ? $projectConfig['alias-group'] : $project->id;
        }

        $appName = $this->selectApp($input);

        /** @var \Platformsh\Cli\Service\Relationships $relationshipsUtil */
        $relationshipsUtil = $this->getService('relationships');

        /** @var \Doctrine\Common\Cache\CacheProvider $cache */
        $cache = $this->getService('cache');
        $portsCacheKey = 'ssh-config:ports';
        $portsCache = $cache->fetch($portsCacheKey) ?: [];

        $indent = str_repeat(' ', 2);

        foreach ($environments as $environment) {
            if (!$environment->hasLink('ssh')) {
                continue;
            }
            $sshUrl = $environment->getSshUrl($appName);
            $sshUrlParts = explode("@", $sshUrl);

            $output->writeln("Host $alias.{$environment->id}");
            $output->writeln($indent . "Hostname {$sshUrlParts[1]}");
            $output->writeln($indent . "User {$sshUrlParts[0]}");

            foreach ($relationshipsUtil->getRelationships($sshUrl) as $relationship => $services) {
                foreach ($services as $serviceKey => $service) {
                    $localPort = $this->getPort($project->id, $environment->id, $relationship, $portsCache);
                    $output->writeln($indent . sprintf(
                        'LocalForward %d %s:%d',
                        $localPort,
                        $service['host'],
                        $service['port']
                    ));
                }
            }

            $output->writeln('');
        }

        $cache->save($portsCacheKey, $portsCache);

        return 0;
    }
}
