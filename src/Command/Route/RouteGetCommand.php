<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Route;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RouteGetCommand extends CommandBase
{
    protected static $defaultName = 'route:get';

    private $api;
    private $formatter;
    private $questionHelper;
    private $selector;

    public function __construct(
        Api $api,
        PropertyFormatter $formatter,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('View a resolved route')
            ->addArgument('route', InputArgument::OPTIONAL, "The route's original URL")
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to display')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Bypass the cache of routes');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->formatter->configureInput($definition);

        $this->addExample('View the URL to the https://{default}/ route', "'https://{default}/' -P url");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environment = $this->selector->getSelection($input)->getEnvironment();

        $routes = $this->api
            ->getCurrentDeployment($environment, $input->getOption('refresh'))
            ->routes;

        $selectedRoute = false;
        $originalUrl = $input->getArgument('route');
        if ($originalUrl === null) {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('The <comment>route</comment> argument is required.');

                return 1;
            }
            $items = [];
            foreach ($routes as $route) {
                $items[$route->original_url] = $route->original_url;
            }
            uksort($items, [$this->api, 'urlSort']);
            $originalUrl = $this->questionHelper->choose($items, 'Enter a number to choose a route:');
        }

        foreach ($routes as $url => $route) {
            if ($route->original_url === $originalUrl) {
                $selectedRoute = $route->getProperties();
                $selectedRoute['url'] = $url;
                break;
            }
        }

        if (!$selectedRoute) {
            $this->stdErr->writeln(sprintf('Route not found: <comment>%s</comment>', $originalUrl));

            return 1;
        }

        $this->formatter->displayData($output, $selectedRoute, $input->getOption('property'));

        return 0;
    }
}
