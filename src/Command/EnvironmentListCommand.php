<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
                'The project ID'
            )
            ->addOption(
                'pipe',
                null,
                InputOption::VALUE_NONE,
                'Output a simple list of environment IDs.'
            );
    }

    /**
     * Build a tree out of a list of environments.
     */
    protected function buildEnvironmentTree($environments, $parent = null)
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
            // Inactive environments have no public url.
            $link = '';
            if (!empty($environment['_links']['public-url'])) {
                $link = $environment['_links']['public-url']['href'];
            }

            $id = str_repeat(' ', $indent) . $environment['id'];
            if ($environment['id'] == $this->currentEnvironment['id']) {
                $id .= "<info>*</info>";
            }
            $rows[] = array(
                $id,
                $environment['title'],
                $link,
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

        $this->currentEnvironment = $this->getCurrentEnvironment($this->project);
        $environments = $this->getEnvironments($this->project);

        if ($input->getOption('pipe')) {
          foreach (array_keys($environments) as $id) {
            $output->writeln($id);
          }
          return;
        }

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

        $output->writeln("\n<info>*</info> - Indicates the current environment.");
        $output->writeln("Checkout a different environment by running <info>platform checkout [id]</info>.");
        if ($this->operationAllowed('branch', $this->currentEnvironment)) {
            $output->writeln("Branch a new environment by running <info>platform environment:branch [name]</info>.");
        }
        if ($this->operationAllowed('activate', $this->currentEnvironment)) {
            $output->writeln("Activate the current environment by running <info>platform environment:activate</info>.");
        }
        if ($this->operationAllowed('deactivate', $this->currentEnvironment)) {
            $output->writeln("Deactivate the current environment by running <info>platform environment:deactivate</info>.");
        }
        if ($this->operationAllowed('delete', $this->currentEnvironment)) {
            $output->writeln("Delete the current environment by running <info>platform environment:delete</info>.");
        }
        if ($this->operationAllowed('backup', $this->currentEnvironment)) {
            $output->writeln("Backup the current environment by running <info>platform environment:backup</info>.");
        }
        if ($this->operationAllowed('merge', $this->currentEnvironment)) {
            $output->writeln("Merge the current environment by running <info>platform environment:merge</info>.");
        }
        if ($this->operationAllowed('synchronize', $this->currentEnvironment)) {
            $output->writeln("Sync the current environment by running <info>platform environment:synchronize</info>.");
        }
        $output->writeln("Execute drush commands against the current environment by running <info>platform drush</info>.");
        // Output a newline after the current block of commands.
        $output->writeln("");
    }
}
