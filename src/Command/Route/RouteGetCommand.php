<?php
namespace Platformsh\Cli\Command\Route;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Client\Model\Route;
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

    /**
     * @return bool
     */
    private function isLocalOnContainer(InputInterface $input) {
        $envPrefix = $this->config()->get('service.env_prefix');
        if ($input->getOption('project')
            && getenv($envPrefix . 'PROJECT')
            && getenv($envPrefix . 'PROJECT') !== $input->getOption('project')) {
            return false;
        }
        if ($input->getOption('environment')
            && getenv($envPrefix . 'BRANCH')
            && getenv($envPrefix . 'BRANCH') !== $input->getOption('environment')) {
            return false;
        }
        if ($input->getOption('app')
            && getenv($envPrefix . 'APPLICATION_NAME')
            && getenv($envPrefix . 'APPLICATION_NAME') !== $input->getOption('app')) {
            return false;
        }

        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Allow override via PLATFORM_ROUTES.
        $prefix = $this->config()->get('service.env_prefix');
        if (getenv($prefix . 'ROUTES') && $this->isLocalOnContainer($input)) {
            $decoded = json_decode(base64_decode(getenv($prefix . 'ROUTES'), true), true);
            if (empty($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'ROUTES');
            }
            $routes = $decoded;
        } else {
            $this->validateInput($input);
            $environment = $this->getSelectedEnvironment();
            $deployment = $this->api()
                ->getCurrentDeployment($environment, $input->getOption('refresh'));
            $routes = array_map(function (Route $route) {
                return $route->getProperties();
            }, $deployment->routes);
        }

        $this->warnAboutDeprecatedOptions(['app', 'identity-file']);

        /** @var array|false $selectedRoute */
        $selectedRoute = false;

        $id = $input->getOption('id');
        if (!$selectedRoute && $id !== null) {
            foreach ($routes as $url => $route) {
                if (isset($route['id']) && $route['id'] === $id) {
                    $selectedRoute = $route;
                    $selectedRoute['url'] = $url;
                    break;
                }
            }
            if (!$selectedRoute) {
                $this->stdErr->writeln(sprintf('No route found with ID: <error>%s</error>', $id));

                return 1;
            }
        }

        if (!$selectedRoute && $input->getOption('primary')) {
            foreach ($routes as $url => $route) {
                if (!empty($route['primary'])) {
                    $selectedRoute = $route;
                    $selectedRoute['url'] = $url;
                    break;
                }
            }
            if (!$selectedRoute) {
                throw new \RuntimeException('No primary route found.');
            }
        }

        $originalUrl = $input->getArgument('route');
        if (!$selectedRoute && $originalUrl === null) {
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
                if (!empty($route['id'])) {
                    $items[$originalUrl] .= ' (<info>' . $route['id'] . '</info>)';
                }
                if (!empty($route['primary'])) {
                    $items[$originalUrl] .= ' - <info>primary</info>';
                }
            }
            uksort($items, [$this->api(), 'urlSort']);
            $originalUrl = $questionHelper->choose($items, 'Enter a number to choose a route:');
        }

        if (!$selectedRoute && $originalUrl !== null) {
            foreach ($routes as $url => $route) {
                if (isset($route['original_url']) && $route['original_url'] === $originalUrl) {
                    $selectedRoute = $route;
                    $selectedRoute['url'] = $url;
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
        $selectedRoute += ['primary' => false, 'id' => null];

        /** @var PropertyFormatter $propertyFormatter */
        $propertyFormatter = $this->getService('property_formatter');

        $propertyFormatter->displayData($output, $selectedRoute, $input->getOption('property'));

        return 0;
    }
}
