<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class EnvironmentListCommand extends EnvironmentCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environments')
            ->setDescription('Get a list of all environments.')
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project id'
            );
        ;
    }

    /**
     * Build a tree out of a list of environments.
     */
    protected function buildEnvironmentTree($environments, $parent = NULL)
    {
        $children = array();
        foreach ($environments as $environment) {
            if ($environment['parent'] === $parent) {
                $environment['children'] = $this->buildEnvironmentTree($environments, $environment['id']);
                $children[$environment['id']] = $environment;
            }
        }
        return $children;
    }

    /**
     * Build a table of environments.
     */
    protected function buildEnvironmentTable($tree)
    {
        $table = $this->getHelperSet()->get('table');
        $table
            ->setHeaders(array('ID', 'Name', 'URL'))
            ->setRows($this->buildEnvironmentRows($tree));

        return $table;
    }

    /**
     * Recursively build rows of the environment table.
     */
    protected function buildEnvironmentRows($tree, $indent = 0)
    {
        $rows = array();
        foreach ($tree as $environment) {
            $rows[] = array(
                str_repeat(' ', $indent) . $environment['id'],
                $environment['title'],
                $environment['_links']['public-url']['href'],
            );

            $rows = array_merge($rows, $this->buildEnvironmentRows($environment['children'], $indent + 1));
        }
        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        $environments = $this->getEnvironments($this->project, TRUE);
        $tree = $this->buildEnvironmentTree($environments);

        // To make the display nicer, we move all the children of master
        // to the top level.
        if (isset($tree['master'])) {
            $tree += $tree['master']['children'];
            $tree['master']['children'] = array();
        }

        $output->writeln("\nYour environments are: ");
        $table = $this->buildEnvironmentTable($tree);
        $table->render($output);

        $output->writeln("\nDelete the current environment by running <info>platform environment:delete</info>.");
        $output->writeln("Backup the current environment by running <info>platform environment:backup</info>.");
        $output->writeln("Merge the current environment by running <info>platform environment:merge</info>.");
        $output->writeln("Sync the current environment by running <info>platform environment:synchronize</info>.");
        $output->writeln("Branch a new environment by running <info>platform environment:branch</info>.");
        $output->writeln("Note: You can specify a different environment using the --environment option.\n");
    }
}
