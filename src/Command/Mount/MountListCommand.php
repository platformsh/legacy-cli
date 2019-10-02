<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountListCommand extends CommandBase
{

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
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addRemoteContainerOptions();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $host = $this->selectHost($input, getenv($this->config()->get('service.env_prefix') . 'APPLICATION'));
        /** @var \Platformsh\Cli\Service\Mount $mountService */
        $mountService = $this->getService('mount');
        if ($host instanceof LocalHost) {
            /** @var \Platformsh\Cli\Service\RemoteEnvVars $envVars */
            $envVars = $this->getService('remote_env_vars');
            $config = (new AppConfig($envVars->getArrayEnvVar('APPLICATION', $host)));
            $mounts = $mountService->mountsFromConfig($config);
        } else {
            $container = $this->selectRemoteContainer($input);
            $mounts = $mountService->mountsFromConfig($container->getConfig());
        }

        if (empty($mounts)) {
            $this->stdErr->writeln(sprintf('No mounts found on host: <info>%s</info>', $host->getLabel()));

            return 1;
        }

        if ($input->getOption('paths')) {
            $output->writeln(array_keys($mounts));

            return 0;
        }

        $header = ['path' => 'Mount path', 'definition' => 'Definition'];
        $rows = [];
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        foreach ($mounts as $path => $definition) {
            $rows[] = ['path' => $path, 'definition' => $formatter->format($definition)];
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $this->stdErr->writeln(sprintf('Mounts on <info>%s</info>:', $host->getLabel()));
        $table->render($rows, $header);

        return 0;
    }
}
