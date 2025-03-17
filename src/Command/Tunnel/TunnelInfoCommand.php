<?php
namespace Platformsh\Cli\Command\Tunnel;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelInfoCommand extends TunnelCommandBase
{
    protected function configure()
    {
        $this
          ->setName('tunnel:info')
          ->setDescription("View relationship info for SSH tunnels")
          ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The relationship property to view')
          ->addOption('encode', 'c', InputOption::VALUE_NONE, 'Output as base64-encoded JSON')
          ->addOption('env', null, InputOption::VALUE_NONE, 'Output as a list of environment variables');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();

        // Deprecated options, left for backwards compatibility
        $this->addHiddenOption('format', null, InputOption::VALUE_REQUIRED, 'DEPRECATED');
        $this->addHiddenOption('columns', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'DEPRECATED');
        $this->addHiddenOption('no-header', null, InputOption::VALUE_NONE, 'DEPRECATED');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->warnAboutDeprecatedOptions(['columns', 'format', 'no-header']);

        /** @var \Platformsh\Cli\Service\Relationships $relationshipsService */
        $relationshipsService = $this->getService('relationships');

        $tunnels = $this->getTunnelInfo();
        $relationships = [];
        foreach ($this->filterTunnels($tunnels, $input) as $tunnel) {
            $service = $tunnel['service'];

            // Overwrite the service's address with the local tunnel details.
            $service = array_merge($service, array_intersect_key([
                'host' => self::LOCAL_IP,
                'ip' => self::LOCAL_IP,
                'port' => $tunnel['localPort'],
            ], $service));

            $service['url'] = $relationshipsService->buildUrl($service);

            $relationships[$tunnel['relationship']][$tunnel['serviceKey']] = $service;
        }
        if (!count($relationships)) {
            $this->stdErr->writeln('No tunnels found.');

            if (count($tunnels) > count($relationships)) {
                $this->stdErr->writeln(sprintf(
                    'List all tunnels with: <info>%s tunnels --all</info>',
                    $this->config()->get('application.executable')
                ));
            }

            return 1;
        }

        if ($input->getOption('env')) {
            if ($input->getOption('property') || $input->getOption('encode')) {
                $this->stdErr->writeln('You cannot combine --env with --encode or --property.');
                return 1;
            }

            $envPrefix = $this->config()->get('service.env_prefix');
            $output->writeln($envPrefix . 'RELATIONSHIPS=' . base64_encode(json_encode($relationships)));

            foreach ($relationships as $name => $services) {
                if (!isset($services[0])) {
                    continue;
                }
                foreach ($services[0] as $key => $value) {
                    if (is_scalar($value)) {
                        $output->writeln(strtoupper($name) . '_' . strtoupper($key) . '=' . $value);
                    }
                }
            }

            return 0;
        }

        if ($input->getOption('encode')) {
            if ($input->getOption('property')) {
                $this->stdErr->writeln('You cannot combine --encode with --property.');
                return 1;
            }

            $output->writeln(base64_encode(json_encode($relationships)));
            return 0;
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $formatter->displayData($output, $relationships, $input->getOption('property'));

        return 0;
    }
}
