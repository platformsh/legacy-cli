<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Route;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RouteListCommand extends CommandBase
{
    protected static $defaultName = 'route:list';

    private $config;
    private $selector;
    private $table;

    public function __construct(
        Config $config,
        Selector $selector,
        Table $table
    ) {
        $this->config = $config;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['routes'])
            ->setDescription('List all routes for an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment ID');
        $this->setHiddenAliases(['environment:routes']);

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environment = $this->selector->getSelection($input)->getEnvironment();

        $routes = $environment->getRoutes();
        if (empty($routes)) {
            $this->stdErr->writeln("No routes found");

            return 0;
        }

        $header = ['Route', 'Type', 'To'];
        $rows = [];
        foreach ($routes as $route) {
            $rows[] = [
                new AdaptiveTableCell($route->id, ['wrap' => false]),
                $route->type,
                $route->type == 'upstream' ? $route->upstream : $route->to,
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln("Routes for the environment <info>{$environment->id}</info>:");
        }

        $this->table->render($rows, $header);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view a single route, run: <info>%s route:get <route></info>',
                $this->config->get('application.executable')
            ));
        }

        return 0;
    }
}
