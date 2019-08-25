<?php
namespace Platformsh\Cli\Command\Route;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Service\PropertyFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RouteGetCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('route:get')
            ->setDescription('View a resolved route')
            ->addArgument('route', InputArgument::OPTIONAL, "The route's original URL")
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'A route ID to select')
            ->addOption('primary', null, InputOption::VALUE_NONE, 'Select the primary route')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to display')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Bypass the cache of routes');
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption()
            ->addEnvironmentOption();
        $this->addOption('app', 'A', InputOption::VALUE_REQUIRED, '[Deprecated option, no longer used]');
        $this->addOption('identity-file', 'i', InputOption::VALUE_REQUIRED, '[Deprecated option, no longer used]');
        $this->addExample('View the URL to the https://{default}/ route', "'https://{default}/' -P url");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Allow override via PLATFORM_ROUTES.
        $prefix = $this->config()->get('service.env_prefix');
        if (getenv($prefix . 'ROUTES') && !LocalHost::conflictsWithCommandLineOptions($input, $prefix)) {
            $this->debug('Reading routes from environment variable ' . $prefix . 'ROUTES');
            $decoded = json_decode(base64_decode(getenv($prefix . 'ROUTES'), true), true);
            if (empty($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'ROUTES');
            }
            $routes = Route::fromVariables($decoded);
        } else {
            $this->debug('Reading routes from the API');
            $this->validateInput($input);
            $environment = $this->getSelectedEnvironment();
            $deployment = $this->api()
                ->getCurrentDeployment($environment, $input->getOption('refresh'));
            $routes = Route::fromDeploymentApi($deployment->routes);
        }

        $this->warnAboutDeprecatedOptions(['app', 'identity-file']);

        /** @var \Platformsh\Cli\Model\Route|false $selectedRoute */
        $selectedRoute = false;

        $id = $input->getOption('id');
        if (!$selectedRoute && $id !== null) {
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
                $this->stdErr->writeln('You must specify a route via the <comment>route</comment> argument or <comment>--id</comment> option.');

                return 1;
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $items = [];
            foreach ($routes as $route) {
                $originalUrl = $route->original_url;
                $items[$originalUrl] = $originalUrl;
                if (!empty($route->id)) {
                    $items[$originalUrl] .= ' (<info>' . $route->id . '</info>)';
                }
                if ($route->primary) {
                    $items[$originalUrl] .= ' - <info>primary</info>';
                }
            }
            uksort($items, [$this->api(), 'urlSort']);
            $originalUrl = $questionHelper->choose($items, 'Enter a number to choose a route:');
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

        /** @var PropertyFormatter $propertyFormatter */
        $propertyFormatter = $this->getService('property_formatter');

        $propertyFormatter->displayData($output, $selectedRoute, $input->getOption('property'));

        return 0;
    }
}
