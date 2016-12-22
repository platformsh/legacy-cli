<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRoutesCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:routes')
            ->setAliases(['routes'])
            ->setDescription('List an environment\'s routes')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment');
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

        $header = ['Route', 'Type', 'To', 'Cache', 'SSI'];
        $rows = [];
        foreach ($routes as $route) {
            $rows[] = [
                $route->id,
                $route->type,
                $route->type == 'upstream' ? $route->upstream : $route->to,
                json_encode($route->cache),
                json_encode($route->ssi),
            ];
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln("Routes for the environment <info>{$environment->id}</info>:");
        }

        $table->render($rows, $header);

        return 0;
    }
}
