<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Mount;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\RemoteContainer\BrokenEnv;
use Platformsh\Cli\Model\RemoteContainer\Worker;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mount:list', description: 'Get a list of mounts', aliases: ['mounts'])]
class MountListCommand extends CommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = ['path' => 'Mount path', 'definition' => 'Definition'];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly Mount $mount, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('paths', null, InputOption::VALUE_NONE, 'Output the mount paths only (one per line)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        Table::configureInput($this->getDefinition(), $this->tableHeader);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addRemoteContainerOptions($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = null;
        if (($applicationEnv = getenv($this->config->getStr('service.env_prefix') . 'APPLICATION'))
            && !LocalHost::conflictsWithCommandLineOptions($input, $this->config->getStr('service.env_prefix'))) {
            $this->io->debug('Selected host: localhost');
            $config = json_decode((string) base64_decode($applicationEnv), true) ?: [];
            $mounts = $this->mount->mountsFromConfig(new AppConfig($config));
            $appName = $config['name'];
            $appType = str_contains((string) $appName, '--') ? 'worker' : 'app';
            if (empty($mounts)) {
                $this->stdErr->writeln(sprintf(
                    'No mounts found in config variable: <info>%s</info>',
                    $this->config->getStr('service.env_prefix') . 'APPLICATION',
                ));

                return 0;
            }
        } else {
            $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));
            $environment = $selection->getEnvironment();
            $container = $selection->getRemoteContainer();
            if ($container instanceof BrokenEnv) {
                $this->stdErr->writeln(sprintf(
                    'Unable to find deployment information for the environment: %s',
                    $this->api->getEnvironmentLabel($environment, 'error'),
                ));
                return 1;
            }
            $mounts = $this->mount->mountsFromConfig($container->getConfig());
            $appName = $container->getName();
            $appType = $container instanceof Worker ? 'worker' : 'app';
            if (empty($mounts)) {
                $this->stdErr->writeln(sprintf(
                    'No mounts found on environment %s, %s <info>%s</info>',
                    $this->api->getEnvironmentLabel($environment),
                    $appType,
                    $appName,
                ));

                return 0;
            }
        }

        if ($input->getOption('paths')) {
            $output->writeln(array_keys($mounts));

            return 0;
        }

        $rows = [];
        foreach ($mounts as $path => $definition) {
            $rows[] = ['path' => $path, 'definition' => $this->propertyFormatter->format($definition)];
        }
        if ($environment !== null) {
            $this->stdErr->writeln(sprintf(
                'Mounts on environment %s, %s <info>%s</info>:',
                $this->api->getEnvironmentLabel($environment),
                $appType,
                $appName,
            ));
        } else {
            $this->stdErr->writeln(sprintf('Mounts on %s <info>%s</info>:', $appType, $appName));
        }
        $this->table->render($rows, $this->tableHeader);

        return 0;
    }
}
