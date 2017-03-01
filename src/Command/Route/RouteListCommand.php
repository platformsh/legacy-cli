<?php
namespace Platformsh\Cli\Command\Route;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment ID');
        $this->setHiddenAliases(['environment:routes']);
        Table::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        $routes = $environment->getRoutes();
        if (empty($routes)) {
            $this->stdErr->writeln("No routes found");

            return 0;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        $header = ['Route', 'Type', 'To'];
        $rows = [];
        foreach ($routes as $route) {
            $rows[] = [
                new AdaptiveTableCell($route->id, ['wrap' => false]),
                $route->type,
                $route->type == 'upstream' ? $route->upstream : $route->to,
            ];
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln("Routes for the environment <info>{$environment->id}</info>:");
        }

        $table->render($rows, $header);

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
