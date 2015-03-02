<?php

namespace Platformsh\Cli\Command;

use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentListCommand extends PlatformCommand
{

    protected $showNames = false;
    protected $showUrls = false;
    protected $showStatus = false;

    protected $children = array();

    /** @var Environment */
    protected $currentEnvironment;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('environment:list')
          ->setAliases(array('environments'))
          ->setDescription('Get a list of all environments')
          ->addOption(
            'pipe',
            null,
            InputOption::VALUE_NONE,
            'Output a simple list of environment IDs.'
          )
          ->addOption(
            'show',
            null,
            InputOption::VALUE_OPTIONAL,
            "Specify information to show about the environment: 'name', 'status', 'url', or 'all'.",
            'name,status'
          )
          ->addOption(
            'refresh',
            null,
            InputOption::VALUE_OPTIONAL,
            'Whether to refresh the list.',
            1
          );
        $this->addProjectOption();
    }

    /**
     * Build a tree out of a list of environments.
     *
     * @param Environment[] $environments
     * @param string        $parent
     *
     * @return array
     */
    protected function buildEnvironmentTree(array $environments, $parent = null)
    {
        $children = array();
        foreach ($environments as $environment) {
            if ($environment['parent'] === $parent) {
                $this->children[$environment['id']] = $this->buildEnvironmentTree(
                  $environments,
                  $environment->getProperty('id')
                );
                $children[$environment['id']] = $environment;
            }
        }

        return $children;
    }

    /**
     * Build a table of environments.
     *
     * @param Environment[] $tree
     * @param OutputInterface $output
     *
     * @return Table
     */
    protected function buildEnvironmentTable($tree, OutputInterface $output)
    {
        $headers = array('ID');
        if ($this->showNames) {
            $headers[] = 'Name';
        }
        if ($this->showStatus) {
            $headers[] = 'Status';
        }
        if ($this->showUrls) {
            $headers[] = 'URL';
        }
        $table = new Table($output);
        $table
          ->setHeaders($headers)
          ->addRows($this->buildEnvironmentRows($tree));

        return $table;
    }

    /**
     * Recursively build rows of the environment table.
     *
     * @param Environment[] $tree
     * @param int           $indent
     *
     * @return array
     */
    protected function buildEnvironmentRows($tree, $indent = 0)
    {
        $rows = array();
        foreach ($tree as $environment) {
            $row = array();

            $id = str_repeat('   ', $indent) . $environment['id'];
            if ($environment['id'] == $this->currentEnvironment['id']) {
                $id .= "<info>*</info>";
            }
            $row[] = $id;

            if ($this->showNames) {
                $row[] = $environment['title'];
            }

            // Inactive environments have no public url.
            $url = '';
            if ($environment->isActive()) {
                $url = $environment->getLink('public-url');
            }

            if ($this->showStatus) {
                $row[] = $url ? 'Active' : 'Inactive';
            }

            if ($this->showUrls) {
                $row[] = $url;
            }

            $rows[] = $row;
            $rows = array_merge($rows, $this->buildEnvironmentRows($this->children[$environment['id']], $indent + 1));
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

        $show = explode(',', $input->getOption('show'));

        if (in_array('all', $show)) {
            $this->showUrls = true;
            $this->showNames = true;
            $this->showStatus = true;
        } elseif ($show) {
            $this->showUrls = in_array('url', $show);
            $this->showNames = in_array('name', $show);
            $this->showStatus = in_array('status', $show);
        }

        $refresh = $input->hasOption('refresh') && $input->getOption('refresh');

        $environments = $this->getEnvironments(null, $refresh);

        if ($input->getOption('pipe')) {
            $output->writeln(array_keys($environments));

            return;
        }

        $this->currentEnvironment = $this->getCurrentEnvironment($this->getSelectedProject());

        $tree = $this->buildEnvironmentTree($environments);

        // To make the display nicer, we move all the children of master
        // to the top level.
        if (isset($tree['master'])) {
            $tree += $this->children['master'];
            $this->children['master'] = array();
        }

        $output->writeln("Your environments are: ");
        $table = $this->buildEnvironmentTable($tree, $output);
        $table->render();

        if (!$this->currentEnvironment) {
            return;
        }

        $output->writeln("<info>*</info> - Indicates the current environment.\n");

        $currentEnvironment = $this->currentEnvironment;

        $output->writeln("Check out a different environment by running <info>platform checkout [id]</info>.");

        if ($currentEnvironment->operationAvailable('branch')) {
            $output->writeln(
              "Branch a new environment by running <info>platform environment:branch [new-name]</info>."
            );
        }
        if ($currentEnvironment->operationAvailable('activate')) {
            $output->writeln(
              "Activate the current environment by running <info>platform environment:activate</info>."
            );
        }
        if ($currentEnvironment->operationAvailable('deactivate')) {
            $output->writeln(
              "Deactivate the current environment by running <info>platform environment:deactivate</info>."
            );
        }
        if ($currentEnvironment->operationAvailable('delete')) {
            $output->writeln("Delete the current environment by running <info>platform environment:delete</info>.");
        }
        if ($currentEnvironment->operationAvailable('backup')) {
            $output->writeln(
              "Back up the current environment by running <info>platform environment:backup</info>."
            );
        }
        if ($currentEnvironment->operationAvailable('merge')) {
            $output->writeln("Merge the current environment by running <info>platform environment:merge</info>.");
        }
        if ($currentEnvironment->operationAvailable('synchronize')) {
            $output->writeln(
              "Sync the current environment by running <info>platform environment:synchronize</info>."
            );
        }

        // Only mention Drush if the command exists, i.e. if it is enabled.
        try {
            $this->getApplication()
                 ->get('drush');
            $output->writeln(
              "Execute Drush commands against the current environment by running <info>platform drush</info>."
            );
        } catch (\InvalidArgumentException $e) {
            // Ignore 'command not found' errors.
        }
    }
}
