<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Route;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'route:list', description: 'List all routes for an environment', aliases: ['routes'])]
class RouteListCommand extends CommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'route' => 'Route',
        'type' => 'Type',
        'to' => 'To',
        'url' => 'URL',
    ];
    /** @var string[] */
    private array $defaultColumns = ['route', 'type', 'to'];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment ID')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Bypass the cache of routes');
        $this->setHiddenAliases(['environment:routes']);
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Allow override via PLATFORM_ROUTES.
        $prefix = $this->config->getStr('service.env_prefix');
        $selection = null;
        if (getenv($prefix . 'ROUTES') && !LocalHost::conflictsWithCommandLineOptions($input, $prefix)) {
            $this->io->debug('Reading routes from environment variable ' . $prefix . 'ROUTES');
            $decoded = json_decode((string) base64_decode(getenv($prefix . 'ROUTES'), true), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'ROUTES');
            }
            $routes = Route::fromVariables($decoded);
            $fromEnv = true;
        } else {
            $this->io->debug('Reading routes from the deployments API');
            $selection = $this->selector->getSelection($input);
            $deployment = $this->api->getCurrentDeployment($selection->getEnvironment(), $input->getOption('refresh'));
            $routes = Route::fromDeploymentApi($deployment->routes);
            $fromEnv = false;
        }
        if (empty($routes)) {
            $this->stdErr->writeln("No routes found");

            return 0;
        }

        $rows = [];
        foreach ($routes as $route) {
            $row = [];
            $row['route'] = new AdaptiveTableCell($route->original_url, ['wrap' => false]);
            $row['type'] = $route->type;
            $row['to'] = $route->type == 'upstream' ? $route->upstream : $route->to;
            $row['url'] = $route->url;
            $rows[] = $row;
        }

        if (!$this->table->formatIsMachineReadable()) {
            if ($fromEnv) {
                $this->stdErr->writeln('Routes in the <info>' . $prefix . 'ROUTES</info> environment variable:');
            } else {
                $this->stdErr->writeln(sprintf(
                    'Routes on the project %s, environment %s:',
                    $this->api->getProjectLabel($selection->getProject()),
                    $this->api->getEnvironmentLabel($selection->getEnvironment()),
                ));
            }
        }

        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view a single route, run: <info>%s route:get <route></info>',
                $this->config->getStr('application.executable'),
            ));
        }

        return 0;
    }
}
