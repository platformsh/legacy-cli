<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\RemoteContainer\BrokenEnv;
use Platformsh\Cli\Model\RemoteContainer\Worker;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountListCommand extends CommandBase
{
    private $tableHeader = ['path' => 'Mount path', 'definition' => 'Definition'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mount:list')
            ->setAliases(['mounts'])
            ->setDescription('Get a list of mounts')
            ->addOption('paths', null, InputOption::VALUE_NONE, 'Output the mount paths only (one per line)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        Table::configureInput($this->getDefinition(), $this->tableHeader);
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addRemoteContainerOptions();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var \Platformsh\Cli\Service\Mount $mountService */
        $mountService = $this->getService('mount');
        if (($applicationEnv = getenv($this->config()->get('service.env_prefix') . 'APPLICATION'))
            && !LocalHost::conflictsWithCommandLineOptions($input, $this->config()->get('service.env_prefix'))) {
            $this->debug('Selected host: localhost');
            $config = json_decode(base64_decode($applicationEnv), true) ?: [];
            $mounts = $mountService->mountsFromConfig(new AppConfig($config));
            $appName = $config['name'];
            $appType = strpos($appName, '--') !== false ? 'worker' : 'app';
            if (empty($mounts)) {
                $this->stdErr->writeln(sprintf(
                    'No mounts found in config variable: <info>%s</info>',
                    $this->config()->get('service.env_prefix') . 'APPLICATION'
                ));

                return 0;
            }
        } else {
            $this->chooseEnvFilter = $this->filterEnvsMaybeActive();
            $this->validateInput($input);
            $environment = $this->getSelectedEnvironment();
            $container = $this->selectRemoteContainer($input);
            if ($container instanceof BrokenEnv) {
                $this->stdErr->writeln(sprintf(
                    'Unable to find deployment information for the environment: %s',
                    $this->api()->getEnvironmentLabel($environment, 'error')
                ));
                return 1;
            }
            $mounts = $mountService->mountsFromConfig($container->getConfig());
            $appName = $container->getName();
            $appType = $container instanceof Worker ? 'worker' : 'app';
            if (empty($mounts)) {
                $this->stdErr->writeln(sprintf(
                    'No mounts found on environment %s, %s <info>%s</info>',
                    $this->api()->getEnvironmentLabel($environment),
                    $appType,
                    $appName
                ));

                return 0;
            }
        }

        if ($input->getOption('paths')) {
            $output->writeln(array_keys($mounts));

            return 0;
        }

        $rows = [];
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        foreach ($mounts as $path => $definition) {
            $rows[] = ['path' => $path, 'definition' => $formatter->format($definition)];
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        if ($this->hasSelectedEnvironment()) {
            $this->stdErr->writeln(sprintf('Mounts on environment %s, %s <info>%s</info>:',
                $this->api()->getEnvironmentLabel($this->getSelectedEnvironment()),
                $appType,
                $appName
            ));
        } else {
            $this->stdErr->writeln(sprintf('Mounts on %s <info>%s</info>:', $appType, $appName));
        }
        $table->render($rows, $this->tableHeader);

        return 0;
    }
}
