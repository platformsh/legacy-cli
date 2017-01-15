<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Service\Table;
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
          ->addOption('encode', 'c', InputOption::VALUE_NONE, 'Output as base64-encoded JSON');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->checkSupport();
        $this->validateInput($input);

        $tunnels = $this->getTunnelInfo();
        $relationships = [];
        foreach ($this->filterTunnels($tunnels, $input) as $key => $tunnel) {
            $service = $tunnel['service'];

            // Overwrite the service's address with the local tunnel details.
            $service = array_merge($service, array_intersect_key([
                'host' => self::LOCAL_IP,
                'ip' => self::LOCAL_IP,
                'port' => $tunnel['localPort'],
            ], $service));

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
