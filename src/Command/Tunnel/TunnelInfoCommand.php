<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Cli\Util\Table;
use Platformsh\Cli\Util\Util;
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
        Table::addFormatOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();
        $environment = $this->getSelectedEnvironment();
        $appName = $this->selectApp($input);

        $tunnels = $this->getTunnelInfo();
        $relationships = [];
        foreach ($this->filterTunnels($tunnels, $input) as $key => $tunnel) {
            $service = $tunnel['service'];

            // Overwrite the service's address with the local tunnel details.
            $service['host'] = self::LOCAL_IP;
            $service['ip'] = self::LOCAL_IP;
            $service['port'] = $tunnel['localPort'];

            $relationships[$tunnel['relationship']][$tunnel['serviceKey']] = $service;
        }
        if (!count($relationships)) {
            $this->stdErr->writeln('No tunnels found.');

            if (count($tunnels) > count($relationships)) {
                $this->stdErr->writeln("List all tunnels with: <info>platform tunnels --all</info>");
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

        $value = $relationships;
        $key = null;
        if ($property = $input->getOption('property')) {
            $parents = explode('.', $property);
            $key = end($parents);
            $value = Util::getNestedArrayValue($relationships, $parents, $key_exists);
            if (!$key_exists) {
                $this->stdErr->writeln("Property not found: <error>$property</error>");

                return 1;
            }
        }

        $formatter = new PropertyFormatter();
        $formatter->yamlInline = 10;
        $output->writeln($formatter->format($value, $key));

        return 0;
    }
}
