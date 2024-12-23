<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Route;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Service\PropertyFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'route:get', description: 'View detailed information about a route')]
class RouteGetCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly PropertyFormatter $propertyFormatter, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('route', InputArgument::OPTIONAL, "The route's original URL")
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'A route ID to select')
            ->addOption('primary', '1', InputOption::VALUE_NONE, 'Select the primary route')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to display')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Bypass the cache of routes');
        PropertyFormatter::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addOption('app', 'A', InputOption::VALUE_REQUIRED, '[Deprecated option, no longer used]');
        $this->addOption('identity-file', 'i', InputOption::VALUE_REQUIRED, '[Deprecated option, no longer used]');
        $this->addExample('View the URL to the https://{default}/ route', "'https://{default}/' -P url");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Allow override via PLATFORM_ROUTES.
        $prefix = $this->config->getStr('service.env_prefix');
        if (getenv($prefix . 'ROUTES') && !LocalHost::conflictsWithCommandLineOptions($input, $prefix)) {
            $this->io->debug('Reading routes from environment variable ' . $prefix . 'ROUTES');
            $decoded = json_decode((string) base64_decode(getenv($prefix . 'ROUTES'), true), true);
            if (empty($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'ROUTES');
            }
            $routes = Route::fromVariables($decoded);
        } else {
            $this->io->debug('Reading routes from the API');
            $selection = $this->selector->getSelection($input);
            $environment = $selection->getEnvironment();
            $deployment = $this->api
                ->getCurrentDeployment($environment, $input->getOption('refresh'));
            $routes = Route::fromDeploymentApi($deployment->routes);
        }

        $this->io->warnAboutDeprecatedOptions(['app', 'identity-file']);

        /** @var Route|false $selectedRoute */
        $selectedRoute = false;

        $id = $input->getOption('id');
        if ($id !== null) {
            foreach ($routes as $route) {
                if ($route->id === $id) {
                    $selectedRoute = $route;
                    break;
                }
            }
            if (!$selectedRoute) {
                $this->stdErr->writeln(sprintf('No route found with ID: <error>%s</error>', $id));

                return 1;
            }
        }

        if (!$selectedRoute && $input->getOption('primary')) {
            foreach ($routes as $route) {
                if ($route->primary) {
                    $selectedRoute = $route;
                    break;
                }
            }
            if (!$selectedRoute) {
                throw new \RuntimeException('No primary route found.');
            }
        }

        $originalUrl = $input->getArgument('route');
        if (!$selectedRoute && ($originalUrl === null || $originalUrl === '')) {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('You must specify a route via the <comment>route</comment> argument, the <comment>--id</comment> option, or the <comment>--primary</comment> option.');

                return 1;
            }
            $items = [];
            $default = null;
            foreach ($routes as $route) {
                $originalUrl = $route->original_url;
                $items[$originalUrl] = $originalUrl;
                if (!empty($route->id)) {
                    $items[$originalUrl] .= ' (<info>' . $route->id . '</info>)';
                }
                if ($route->primary) {
                    $default = $originalUrl;
                    $items[$originalUrl] .= ' - <info>primary</info>';
                }
            }
            $originalUrl = $this->questionHelper->choose($items, 'Enter a number to choose a route:', $default);
        }

        if (!$selectedRoute && $originalUrl !== null && $originalUrl !== '') {
            foreach ($routes as $route) {
                if ($route->original_url === $originalUrl) {
                    $selectedRoute = $route;
                    break;
                }
            }

            if (!$selectedRoute) {
                $this->stdErr->writeln(sprintf('No route found for original URL: <comment>%s</comment>', $originalUrl));

                return 1;
            }
        }

        if (!$selectedRoute) {
            $this->stdErr->writeln('No route found.');

            return 1;
        }

        // Add defaults.
        $selectedRoute = $selectedRoute->getProperties();

        $this->propertyFormatter->displayData($output, $selectedRoute, $input->getOption('property'));

        return 0;
    }
}
