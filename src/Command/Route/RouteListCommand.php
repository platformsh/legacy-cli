<?php
namespace Platformsh\Cli\Command\Route;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RouteListCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('route:list')
            ->setAliases(['routes'])
            ->setDescription('List all routes for an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment ID')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Bypass the cache of routes');
        $this->setHiddenAliases(['environment:routes']);
        Table::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Allow override via PLATFORM_ROUTES.
        $prefix = $this->config()->get('service.env_prefix');
        if (getenv($prefix . 'ROUTES') && !LocalHost::conflictsWithCommandLineOptions($input, $prefix)) {
            $this->debug('Reading routes from environment variable ' . $prefix . 'ROUTES');
            $decoded = json_decode(base64_decode(getenv($prefix . 'ROUTES'), true), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'ROUTES');
            }
            $routes = Route::fromVariables($decoded);
            $fromEnv = true;
        } else {
            $this->debug('Reading routes from the deployments API');
            $this->validateInput($input);
            $environment = $this->getSelectedEnvironment();
            $deployment = $this->api()->getCurrentDeployment($environment, $input->getOption('refresh'));
            $routes = Route::fromDeploymentApi($deployment->routes);
            $fromEnv = false;
        }
        if (empty($routes)) {
            $this->stdErr->writeln("No routes found");

            return 0;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        $header = [
            'route' => 'Route',
            'type' => 'Type',
            'to' => 'To',
            'url' => 'URL',
        ];
        $defaultColumns = ['route', 'type', 'to'];
        $rows = [];
        foreach ($routes as $route) {
            $row = [];
            $row['route'] = new AdaptiveTableCell($route->original_url, ['wrap' => false]);
            $row['type'] = $route->type;
            $row['to'] = $route->type == 'upstream' ? $route->upstream : $route->to;
            $row['url'] = $route->url;
            $rows[] = $row;
        }

        if (!$table->formatIsMachineReadable()) {
            if ($fromEnv) {
                $this->stdErr->writeln('Routes in the <info>' . $prefix . 'ROUTES</info> environment variable:');
            }
            if (isset($environment) && !$fromEnv) {
                $this->stdErr->writeln(sprintf(
                    'Routes on the project %s, environment %s:',
                    $this->api()->getProjectLabel($this->getSelectedProject()),
                    $this->api()->getEnvironmentLabel($environment)
                ));
            }
        }

        $table->render($rows, $header, $defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view a single route, run: <info>%s route:get <route></info>',
                $this->config()->get('application.executable')
            ));
        }

        return 0;
    }
}
