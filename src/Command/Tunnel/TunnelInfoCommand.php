<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\TunnelManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'tunnel:info', description: "View relationship info for SSH tunnels")]
class TunnelInfoCommand extends TunnelCommandBase
{
    public function __construct(private readonly Config $config, private readonly Io $io, private readonly PropertyFormatter $propertyFormatter, private readonly Relationships $relationships, private readonly Selector $selector, private readonly TunnelManager $tunnelManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
          ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The relationship property to view')
          ->addOption('encode', 'c', InputOption::VALUE_NONE, 'Output as base64-encoded JSON');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);

        // Deprecated options, left for backwards compatibility
        $this->addHiddenOption('format', null, InputOption::VALUE_REQUIRED, 'DEPRECATED');
        $this->addHiddenOption('columns', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'DEPRECATED');
        $this->addHiddenOption('no-header', null, InputOption::VALUE_NONE, 'DEPRECATED');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->warnAboutDeprecatedOptions(['columns', 'format', 'no-header']);

        $tunnels = $this->tunnelManager->getTunnels();
        $relationships = [];
        foreach ($this->tunnelManager->filterBySelection($tunnels, $this->selector->getSelection($input)) as $tunnel) {
            $service = $tunnel->metadata['service'];

            // Overwrite the service's address with the local tunnel details.
            $service = array_merge($service, array_intersect_key([
                'host' => TunnelManager::LOCAL_IP,
                'ip' => TunnelManager::LOCAL_IP,
                'port' => $tunnel->localPort,
            ], $service));

            $service['url'] = $this->relationships->buildUrl($service);

            $relationships[$tunnel->metadata['relationship']][$tunnel->metadata['serviceKey']] = $service;
        }
        if (!count($relationships)) {
            $this->stdErr->writeln('No tunnels found.');

            if (count($tunnels) > count($relationships)) {
                $this->stdErr->writeln(sprintf(
                    'List all tunnels with: <info>%s tunnels --all</info>',
                    $this->config->getStr('application.executable'),
                ));
            }

            return 1;
        }

        if ($input->getOption('encode')) {
            if ($input->getOption('property')) {
                $this->stdErr->writeln('You cannot combine --encode with --property.');
                return 1;
            }

            $output->writeln(base64_encode((string) json_encode($relationships)));
            return 0;
        }
        $this->propertyFormatter->displayData($output, $relationships, $input->getOption('property'));

        return 0;
    }
}
