<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentListCommand extends CommandBase
{

    protected $showNames = false;
    protected $showUrls = false;
    protected $showStatus = false;

    protected $children = [];

    /** @var Environment */
    protected $currentEnvironment;
    protected $mapping = [];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:list')
            ->setAliases(['environments'])
            ->setDescription('Get a list of environments')
            ->addOption(
                'no-inactive',
                'I',
                InputOption::VALUE_NONE,
                'Do not show inactive environments'
            )
            ->addOption(
                'pipe',
                null,
                InputOption::VALUE_NONE,
                'Output a simple list of environment IDs.'
            )
            ->addOption(
                'show',
                null,
                InputOption::VALUE_REQUIRED,
                "Specify information to show about the environment: 'name', 'status', 'url', or 'all'.",
                'name,status'
            )
            ->addOption(
                'refresh',
                null,
                InputOption::VALUE_REQUIRED,
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
        $children = [];
        foreach ($environments as $environment) {
            if ($environment->parent === $parent) {
                $this->children[$environment->id] = $this->buildEnvironmentTree(
                    $environments,
                    $environment->id
                );
                $children[$environment->id] = $environment;
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
        $headers = ['ID'];
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
        $rows = [];
        foreach ($tree as $environment) {
            $row = [];

            $id = str_repeat('   ', $indent) . $environment->id;
            if ($environment->id == $this->currentEnvironment->id) {
                $id .= "<info>*</info>";
            }
            $row[] = $id;

            if ($this->showNames) {
                if ($branch = array_search($environment->id, $this->mapping)) {
                    $row[] = sprintf('%s (%s)', $environment->title, $branch);
                }
                else {
                    $row[] = $environment->title;
                }
            }

            // Inactive environments have no public url.
            $url = '';
            if ($environment->isActive()) {
                $url = $environment->getLink('public-url');
            }

            if ($this->showStatus) {
                $row[] = $this->formatEnvironmentStatus($environment->status);
            }

            if ($this->showUrls) {
                $row[] = $url;
            }

            $rows[] = $row;
            $rows = array_merge($rows, $this->buildEnvironmentRows($this->children[$environment->id], $indent + 1));
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

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

        if ($input->getOption('no-inactive')) {
            $environments = array_filter($environments, function ($environment) {
                return $environment->status !== 'inactive';
            });
        }

        if ($input->getOption('pipe')) {
            $output->writeln(array_keys($environments));

            return;
        }

        $project = $this->getSelectedProject();
        $this->currentEnvironment = $this->getCurrentEnvironment($project);

        if (($currentProject = $this->getCurrentProject()) && $currentProject == $project) {
            $config = $this->getProjectConfig($this->getProjectRoot());
            if (isset($config['mapping'])) {
                $this->mapping = $config['mapping'];
            }
        }

        $tree = $this->buildEnvironmentTree($environments);

        // To make the display nicer, we move all the children of master
        // to the top level.
        if (isset($tree['master'])) {
            $tree += $this->children['master'];
            $this->children['master'] = [];
        }

        $this->stdErr->writeln("Your environments are: ");
        $table = $this->buildEnvironmentTable($tree, $output);
        $table->render();

        if (!$this->currentEnvironment) {
            return;
        }

        $this->stdErr->writeln("<info>*</info> - Indicates the current environment\n");

        $currentEnvironment = $this->currentEnvironment;

        $this->stdErr->writeln("Check out a different environment by running <info>platform checkout [id]</info>");

        if ($currentEnvironment->operationAvailable('branch')) {
            $this->stdErr->writeln(
                "Branch a new environment by running <info>platform environment:branch [new-name]</info>"
            );
        }
        if ($currentEnvironment->operationAvailable('activate')) {
            $this->stdErr->writeln(
                "Activate the current environment by running <info>platform environment:activate</info>"
            );
        }
        if ($currentEnvironment->operationAvailable('delete')) {
            $this->stdErr->writeln("Delete the current environment by running <info>platform environment:delete</info>");
        }
        if ($currentEnvironment->operationAvailable('backup')) {
            $this->stdErr->writeln(
                "Back up the current environment by running <info>platform environment:backup</info>"
            );
        }
        if ($currentEnvironment->operationAvailable('merge')) {
            $this->stdErr->writeln("Merge the current environment by running <info>platform environment:merge</info>");
        }
        if ($currentEnvironment->operationAvailable('synchronize')) {
            $this->stdErr->writeln(
                "Sync the current environment by running <info>platform environment:synchronize</info>"
            );
        }
    }

    /**
     * @param string $status
     *
     * @return string
     */
    protected function formatEnvironmentStatus($status) {
        if ($status == 'dirty') {
            $status = 'In progress';
        }

        return ucfirst($status);
    }
}
