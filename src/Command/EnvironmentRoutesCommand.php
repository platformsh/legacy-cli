<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRoutesCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('environment:routes')
          ->setAliases(array('routes'))
          ->setDescription('List an environment\'s routes')
          ->addArgument('environment', InputArgument::OPTIONAL, 'The environment');
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environment = $this->getSelectedEnvironment();

        $routes = $environment->getRoutes();

        $header = array('Route', 'Type', 'To', 'Cache', 'SSI');

        $rows = array();
        foreach ($routes as $route) {
            $rows[] = array(
                $route->id,
                $route->type,
                $route->type == 'upstream' ? $route->upstream : $route->to,
                wordwrap(json_encode($route->cache), 30, "\n", true),
                wordwrap(json_encode($route->ssi), 30, "\n", true),
            );
        }

        $output->writeln("Routes for the environment <info>{$environment->id}</info>:");

        $table = new Table($output);
        $table->setHeaders($header);
        $table->setRows($rows);
        $table->render();

        return 0;
    }
}
