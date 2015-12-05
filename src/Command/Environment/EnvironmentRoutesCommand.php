<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Helper\Table;
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

        $header = ['Route', 'Type', 'To', 'Cache', 'SSI'];

        $rows = [];
        foreach ($routes as $route) {
            $rows[] = [
                $route->id,
                $route->type,
                $route->type == 'upstream' ? $route->upstream : $route->to,
                wordwrap(json_encode($route->cache), 30, "\n", true),
                wordwrap(json_encode($route->ssi), 30, "\n", true),
            ];
        }

        $this->stdErr->writeln("Routes for the environment <info>{$environment->id}</info>:");

        $table = new Table($output);
        $table->setHeaders($header);
        $table->setRows($rows);
        $table->render();

        return 0;
    }
}
